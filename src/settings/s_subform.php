<?php
// src/settings/s_subform.php -- HotCRP settings > submission form page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class BanalSettings {
    static public function render($prefix, $sv) {
        global $Conf;
        $bsetting = explode(";", preg_replace("/>.*/", "", $Conf->setting_data($prefix, "")));
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodyleading"] as $i => $name) {
            $val = get($bsetting, $i, "");
            $sv->set_oldv("{$prefix}_$name", $val == "" ? "N/A" : $val);
        }

        echo '<table class="', ($sv->curv($prefix) ? "foldo" : "foldc"), '" data-fold="true">';
        $sv->echo_checkbox_row($prefix, "PDF format checker<span class=\"fx\">:</span>",
                               "void foldup(this,null,{f:!this.checked})");
        echo '<tr class="fx"><td></td><td class="top">',
            Ht::hidden("has_$prefix", 1),
            '<table><tbody class="secondary-settings">';
        $sv->echo_entry_row("{$prefix}_papersize", "Paper size", "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”,<br />“letter OR A4”");
        $sv->echo_entry_row("{$prefix}_pagelimit", "Page limit");
        $sv->echo_entry_row("{$prefix}_textblock", "Text block", "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”");
        echo '</tbody></table></td><td><span class="sep"></span></td>',
            '<td class="top"><table><tbody class="secondary-settings">';
        $sv->echo_entry_row("{$prefix}_bodyfontsize", "Minimum body font size", null, "&nbsp;pt");
        $sv->echo_entry_row("{$prefix}_bodyleading", "Minimum leading", null, "&nbsp;pt");
        $sv->echo_entry_row("{$prefix}_columns", "Columns");
        echo "</tbody></table></td></tr></table>";
    }
    static private function old_zoomarg() {
        global $Conf;
        $data = $Conf->setting_data("sub_banal");
        if (($gt = strpos($data, ">")) !== false)
            return substr($data, $gt);
        return "";
    }
    static private function check_banal($sv) {
        global $ConfSitePATH;
        $cf = new CheckFormat;

        // Perhaps we have an old pdftohtml with a bad -zoom.
        $zoomarg = "";
        for ($tries = 0; $tries < 2; ++$tries) {
            $cf->errors = 0;
            $s1 = $cf->check_file("$ConfSitePATH/src/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
            if ($s1 == 1 && ($cf->errors & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
                $zoomarg = ">-zoom=1";
            else if ($s1 == 2 || $tries == 0)
                break;
            else
                $zoomarg = "";
        }

        // verify that banal works
        $e1 = $cf->errors;
        $s2 = $cf->check_file("$ConfSitePATH/src/sample.pdf", "a4;1;;3inx3in;14;15" . $zoomarg);
        $e2 = $cf->errors;
        $want_e2 = CheckFormat::ERR_PAPERSIZE | CheckFormat::ERR_PAGELIMIT
            | CheckFormat::ERR_TEXTBLOCK | CheckFormat::ERR_BODYFONTSIZE
            | CheckFormat::ERR_BODYLEADING;
        if ($s1 != 2 || $e1 != 0 || $s2 != 1 || ($e2 & $want_e2) != $want_e2) {
            $errors = "<div class=\"fx\"><table><tr><td>Analysis:&nbsp;</td><td>$s1 $e1 $s2 $e2 (expected 2 0 1 $want_e2)</td></tr>"
                . "<tr><td class=\"nw\">Exit status:&nbsp;</td><td>" . htmlspecialchars($cf->banal_status) . "</td></tr>";
            if (trim($cf->banal_stdout))
                $errors .= "<tr><td>Stdout:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stdout) . "</pre></td></tr>";            if (trim($cf->banal_stdout))
            if (trim($cf->banal_stderr))
                $errors .= "<tr><td>Stderr:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stderr) . "</pre></td></tr>";
            $errors .= "<tr><td>Check:&nbsp;</td><td>" . join("<br />\n", array_map(function ($x) { return $x[1]; }, $cf->msgs)) . "</td></tr>";
            $sv->set_warning(null, "Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldbutton("banal_warning", 0, "Checker output") . $errors . "</table></div></div>");
        }

        return $zoomarg;
    }
    static public function parse($prefix, $sv, $check) {
        global $Conf, $ConfSitePATH;
        if (!isset($sv->req[$prefix])) {
            $sv->save($prefix, 0);
            return false;
        }

        // check banal subsettings
        $old_error_count = $sv->error_count();
        $bs = array_fill(0, 6, "");
        if (($s = trim(defval($sv->req, "{$prefix}_papersize", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
            $sout = array();
            foreach ($ses as $ss)
                if ($ss != "" && CheckFormat::parse_dimen($ss, 2))
                    $sout[] = $ss;
                else if ($ss != "") {
                    $sv->set_error("{$prefix}_papersize", "Invalid paper size.");
                    $sout = null;
                    break;
                }
            if ($sout && count($sout))
                $bs[0] = join(" OR ", $sout);
        }

        if (($s = trim(defval($sv->req, "{$prefix}_pagelimit", ""))) != ""
            && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) > 0)
                $bs[1] = $sx;
            else if (preg_match('/\A(\d+)\s*-\s*(\d+)\z/', $s, $m)
                     && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
                $bs[1] = +$m[1] . "-" . +$m[2];
            else
                $sv->set_error("{$prefix}_pagelimit", "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.");
        }

        if (($s = trim(defval($sv->req, "{$prefix}_columns", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) >= 0)
                $bs[2] = ($sx > 0 ? $sx : $bs[2]);
            else
                $sv->set_error("{$prefix}_columns", "Columns must be a whole number.");
        }

        if (($s = trim(defval($sv->req, "{$prefix}_textblock", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            // change margin specifications into text block measurements
            if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
                $s = $m[1];
                if (!($ps = CheckFormat::parse_dimen($bs[0]))) {
                    $sv->set_error("{$prefix}_pagesize", "You must specify a page size as well as margins.");
                    $sv->set_error("{$prefix}_textblock");
                } else if (strpos($s, "x") !== false) {
                    if (!($m = CheckFormat::parse_dimen($s)) || !is_array($m) || count($m) > 4) {
                        $sv->set_error("{$prefix}_textblock", "Invalid margin definition.");
                        $s = "";
                    } else if (count($m) == 2)
                        $s = array($ps[0] - 2 * $m[0], $ps[1] - 2 * $m[1]);
                    else if (count($m) == 3)
                        $s = array($ps[0] - 2 * $m[0], $ps[1] - $m[1] - $m[2]);
                    else
                        $s = array($ps[0] - $m[0] - $m[2], $ps[1] - $m[1] - $m[3]);
                } else {
                    $s = preg_replace('/\s+/', 'x', $s);
                    if (!($m = CheckFormat::parse_dimen($s)) || (is_array($m) && count($m) > 4))
                        $sv->set_error("{$prefix}_textblock", "Invalid margin definition.");
                    else if (!is_array($m))
                        $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
                    else if (count($m) == 2)
                        $s = array($ps[0] - 2 * $m[1], $ps[1] - 2 * $m[0]);
                    else if (count($m) == 3)
                        $s = array($ps[0] - 2 * $m[1], $ps[1] - $m[0] - $m[2]);
                    else
                        $s = array($ps[0] - $m[1] - $m[3], $ps[1] - $m[0] - $m[2]);
                }
                $s = (is_array($s) ? CheckFormat::unparse_dimen($s) : "");
            }
            // check text block measurements
            if ($s && !CheckFormat::parse_dimen($s, 2))
                $sv->set_error("{$prefix}_textblock", "Invalid text block definition.");
            else if ($s)
                $bs[3] = $s;
        }

        if (($s = trim(defval($sv->req, "{$prefix}_bodyfontsize", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (!is_numeric($s) || $s <= 0)
                $sv->error("{$prefix}_bodyfontsize", "Minimum body font size must be a number bigger than 0.");
            else
                $bs[4] = $s;
        }

        if (($s = trim(defval($sv->req, "{$prefix}_bodyleading", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (!is_numeric($s) || $s <= 0)
                $sv->error("{$prefix}_bodyleading", "Minimum body leading must be a number bigger than 0.");
            else
                $bs[5] = $s;
        }

        if ($sv->error_count() == $old_error_count) {
            while (count($bs) > 0 && $bs[count($bs) - 1] == "")
                array_pop($bs);
            $zoomarg = $check ? self::check_banal($sv) : self::old_zoomarg();
            $sv->save("{$prefix}_data", join(";", $bs) . $zoomarg);
        }

        return false;
    }
}

class SettingRenderer_SubForm extends SettingRenderer {
    private function render_option($sv, $o) {
        global $Conf;

        if ($o)
            $id = $o->id;
        else {
            $o = PaperOption::make(array("id" => $o,
                    "name" => "(Enter new option)",
                    "description" => "",
                    "type" => "checkbox",
                    "position" => count(PaperOption::nonfixed_option_list()) + 1,
                    "display" => "default"));
            $id = "n";
        }

        if ($sv->use_req() && isset($sv->req["optn$id"])) {
            $o = PaperOption::make(array("id" => $id,
                    "name" => $sv->req["optn$id"],
                    "description" => get($sv->req, "optd$id"),
                    "type" => get($sv->req, "optvt$id", "checkbox"),
                    "visibility" => get($sv->req, "optp$id", ""),
                    "position" => get($sv->req, "optfp$id", 1),
                    "display" => get($sv->req, "optdt$id")));
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

        $show_final = $Conf->collectFinalPapers();
        foreach (PaperOption::nonfixed_option_list() as $ox)
            $show_final = $show_final || $ox->final;

        $otypes = array();
        if ($show_final)
            $otypes["xxx1"] = array("optgroup", "Options for submissions");
        $otypes["checkbox"] = "Checkbox";
        $otypes["selector"] = "Selector";
        $otypes["radio"] = "Radio buttons";
        $otypes["numeric"] = "Numeric";
        $otypes["text"] = "Text";
        if ($o->type == "text" && $o->display_space > 3 && $o->display_space != 5)
            $otypes[$optvt] = "Multiline text";
        else
            $otypes["text:ds_5"] = "Multiline text";
        $otypes["pdf"] = "PDF";
        $otypes["slides"] = "Slides";
        $otypes["video"] = "Video";
        $otypes["attachments"] = "Attachments";
        if ($show_final) {
            $otypes["xxx2"] = array("optgroup", "Options for accepted papers");
            $otypes["pdf:final"] = "Alternate final version";
            $otypes["slides:final"] = "Final slides";
            $otypes["video:final"] = "Final video";
            $otypes["attachments:final"] = "Final attachments";
        }
        echo Ht::select("optvt$id", $otypes, $optvt, array("onchange" => "do_option_type(this)", "id" => "optvt$id")),
            "</div></div></td>\n";
        $Conf->footerScript("do_option_type(\$\$('optvt$id'),true)");

        echo "<td class='fn2 pad'><div class='f-i'><div class='f-c'>",
            $sv->label("optp$id", "Visibility"), "</div><div class='f-e'>",
            Ht::select("optp$id", array("admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"), $o->visibility, array("id" => "optp$id")),
            "</div></div></td>\n";

        echo "<td class='pad'><div class='f-i'><div class='f-c'>",
            $sv->label("optfp$id", "Form order"), "</div><div class='f-e'>";
        $x = array();
        // can't use "foreach (PaperOption::nonfixed_option_list())" because caller
        // uses cursor
        for ($n = 0; $n < count(PaperOption::nonfixed_option_list()); ++$n)
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

function render($sv) {
    global $Conf, $Opt;

    echo "<h3 class=\"settings\">Abstract and PDF</h3>\n";

    echo Ht::select("sub_noabstract", [0 => "Abstract required", 2 => "Abstract optional", 1 => "No abstract"], opt_yes_no_optional("noAbstract"));

    echo " <span class=\"barsep\">·</span> ", Ht::select("sub_nopapers", array(0 => "PDF upload required", 2 => "PDF upload optional", 1 => "No PDF"), opt_yes_no_optional("noPapers"));

    if (is_executable("src/banal")) {
        echo "<div class='g'></div>";
        BanalSettings::render("sub_banal", $sv);
    }

    echo "<h3 class=\"settings\">Conflicts &amp; collaborators</h3>\n",
        "<table id=\"foldpcconf\" class=\"fold",
        ($sv->curv("sub_pcconf") ? "o" : "c"), "\">\n";
    $sv->echo_checkbox_row("sub_pcconf", "Collect authors’ PC conflicts",
                           "void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    $conf = array();
    foreach (Conflict::$type_descriptions as $n => $d)
        if ($n)
            $conf[] = "“{$d}”";
    $sv->echo_checkbox("sub_pcconfsel", "Require conflict descriptions (" . commajoin($conf, "or") . ")");
    echo "</td></tr>\n";
    $sv->echo_checkbox_row("sub_collab", "Collect authors’ other collaborators as text");
    echo "</table>\n";


    echo "<h3 class=\"settings\">Submission options</h3>\n";
    echo "Options are selected by authors at submission time.  Examples have included “PC-authored paper,” “Consider this paper for a Best Student Paper award,” and “Allow the shadow PC to see this paper.”  The “option name” should be brief (“PC paper,” “Best Student Paper,” “Shadow PC”).  The optional description can explain further and may use XHTML.  ";
    echo "Add options one at a time.\n";
    echo "<div class='g'></div>\n",
        Ht::hidden("has_options", 1);
    $sep = "";
    $all_options = array_merge(PaperOption::nonfixed_option_list()); // get our own iterator
    foreach ($all_options as $o) {
        echo $sep;
        $this->render_option($sv, $o);
        $sep = "\n<div style=\"margin-top:3em\"></div>\n";
    }

    echo $sep;
    $this->render_option($sv, null);


    // Topics
    // load topic interests
    $qinterest = $Conf->query_topic_interest();
    $result = $Conf->q("select topicId, if($qinterest>0,1,0), count(*) from TopicInterest where $qinterest!=0 group by topicId, $qinterest>0");
    $interests = array();
    $ninterests = 0;
    while (($row = edb_row($result))) {
        if (!isset($interests[$row[0]]))
            $interests[$row[0]] = array();
        $interests[$row[0]][$row[1]] = $row[2];
        $ninterests += ($row[2] ? 1 : 0);
    }

    echo "<h3 class=\"settings g\">Topics</h3>\n";
    echo "Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.\n";
    echo "<div class='g'></div>",
        Ht::hidden("has_topics", 1),
        "<table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
    echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
    $td1 = '<td class="lcaption">Current</td>';
    foreach ($Conf->topic_map() as $tid => $tname) {
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
    function crosscheck($sv) {
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("options")
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            $options = json_decode($sv->newv("options"));
            foreach ((array) $options as $id => $o)
                if (get($o, "visibility") === "nonblind")
                    $sv->set_warning("optp$id", "The “" . htmlspecialchars($o->name) . "” option is “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }
    }
}


class Topic_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        foreach (["TopicArea", "PaperTopic", "TopicInterest"] as $t)
            $sv->need_lock[$t] = true;
        return true;
    }

    public function save($sv, $si) {
        global $Conf;
        $tmap = $Conf->topic_map();
        foreach ($sv->req as $k => $v)
            if ($k === "topnew") {
                $news = array();
                foreach (explode("\n", $v) as $n)
                    if (($n = simplify_whitespace($n)) !== "")
                        $news[] = "('" . sqlq($n) . "')";
                if (count($news))
                    $Conf->qe("insert into TopicArea (topicName) values " . join(",", $news));
            } else if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                       && ctype_digit(substr($k, 3))) {
                $k = (int) substr($k, 3);
                $v = simplify_whitespace($v);
                if ($v == "") {
                    $Conf->qe("delete from TopicArea where topicId=$k");
                    $Conf->qe("delete from PaperTopic where topicId=$k");
                    $Conf->qe("delete from TopicInterest where topicId=$k");
                } else if (isset($tmap[$k]) && $v != $tmap[$k] && !ctype_digit($v))
                    $Conf->qe("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k");
            }
        $Conf->invalidate_topics();
    }
}


class Option_SettingParser extends SettingParser {
    private $stashed_options = false;

    function option_request_to_json($sv, &$new_opts, $id, $current_opts) {
        global $Conf;

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
            $nextid = max($Conf->setting("next_optionid", 1), 1);
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
                $sv->set_error("optd$id", $err);
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
                $sv->set_error("optv$id", "Enter selectors one per line.");
        }

        $oarg["visibility"] = defval($sv->req, "optp$id", "rev");
        if ($oarg["final"])
            $oarg["visibility"] = "rev";

        $oarg["position"] = (int) defval($sv->req, "optfp$id", 1);

        $oarg["display"] = defval($sv->req, "optdt$id");
        if ($oarg["type"] === "pdf" && $oarg["final"])
            $oarg["display"] = "submission";

        $new_opts[$oarg["id"]] = $o = PaperOption::make($oarg);
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

    function parse($sv, $si) {
        $current_opts = PaperOption::nonfixed_option_list();

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
                $sv->set_error("optn$o->req_id", "Option name “" . htmlspecialchars($o->name) . "” is reserved. Please pick another option name.");
            else if (get($optabbrs, $o->abbr))
                $sv->set_error("optn$o->req_id", "Multiple options abbreviate to “{$o->abbr}”. Please pick option names that abbreviate uniquely.");
            else
                $optabbrs[$o->abbr] = $o;

        if (!$sv->has_errors()) {
            $this->stashed_options = $new_opts;
            $sv->need_lock["PaperOption"] = true;
            return true;
        }
    }

    public function save($sv, $si) {
        global $Conf;
        $new_opts = $this->stashed_options;
        $current_opts = PaperOption::nonfixed_option_list();
        $this->option_clean_form_positions($new_opts, $current_opts);

        $newj = (object) array();
        uasort($new_opts, array("PaperOption", "compare"));
        $nextid = max($Conf->setting("next_optionid", 1), $Conf->setting("options", 1));
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
            $Conf->qe("delete from PaperOption where optionId in (" . join(",", $deleted_ids) . ")");

        // invalidate cached option list
        PaperOption::invalidate_option_list();
    }
}


class Banal_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        return BanalSettings::parse("sub_banal", $sv, true);
    }
}


SettingGroup::register("subform", "Submission form", 400, new SettingRenderer_SubForm);
SettingGroup::register_synonym("opt", "subform");
