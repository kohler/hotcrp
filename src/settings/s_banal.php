<?php
// settings/s_banal.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Banal_Setting {
    /** The canonical name of the checked document’s option; also
     * stored in `id`, the object-list anchor.
     * @var string */
    public $doctype = "";
    /** @var string */
    public $id;
    /** @var bool */
    public $active = false;
    /** @var string */
    public $papersize = "";
    /** @var string */
    public $pagelimit = "";
    /** @var string */
    public $columns = "";
    /** @var string */
    public $textblock = "";
    /** @var string */
    public $bodyfontsize = "";
    /** @var string */
    public $bodylineheight = "";
    /** @var string */
    public $unlimitedref = "";
    /** @var string */
    public $wordlimit = "";
    /** @var bool */
    public $appendix = true;
    /** @var bool */
    public $deleted = false;
    /** The parsed form of the string members: banal constraints match
     * the overlaid specification the string members unparse. Non-banal
     * components (`checkers`, `quietpages`) belong to the conference’s
     * stored specification and never include option-spec values.
     * @var FormatSpec */
    public $spec;

    function __construct() {
        $this->spec = new FormatSpec;
    }

    function __clone() {
        $this->spec = clone $this->spec;
    }

    /** @param SettingValues $sv
     * @param PaperOption $opt
     * @return Banal_Setting */
    static function make_document($sv, $opt) {
        $bs = new Banal_Setting;
        $bs->doctype = $bs->id = $opt->json_key();
        // NB the database stores doctypes as option IDs
        $sid = $opt->id < 0 ? "m" . -$opt->id : (string) $opt->id;
        $bs->active = $sv->oldv("fmtstore_v_{$sid}") > 0;
        $cfs = new FormatSpec($sv->oldv("fmtstore_o_{$sid}"), $sv->oldv("fmtstore_s_{$sid}"));
        $sfs = new FormatSpec($sv->oldv("fmtstore_s_{$sid}"));
        $bs->spec = clone $cfs;
        $bs->spec->checkers = $sfs->checkers;
        $bs->spec->quietpages = $sfs->quietpages;
        $bs->spec->timestamp = $sfs->timestamp;
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight", "unlimitedref", "wordlimit"] as $k) {
            $bs->$k = $cfs->unparse_key($k);
        }
        $bs->appendix = !$cfs->unparse_key("noappendix");
        return $bs;
    }
}

class Banal_SettingParser extends SettingParser {
    /** @var bool */
    private $checked = false;

    /** Return the option whose document is checked by format
     * specifications with document type `$doctype`. Any unique option
     * name is accepted; format checking is only supported for the
     * submission and final version documents.
     * @param string $doctype
     * @return ?PaperOption */
    static function doctype_option(Conf $conf, $doctype) {
        $opt = $conf->options()->find($doctype);
        if ($opt
            && ($opt->id === DTYPE_SUBMISSION || $opt->id === DTYPE_FINAL)) {
            return $opt;
        }
        return null;
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        assert($si->name === "format");
        $m = [];
        foreach ([DTYPE_SUBMISSION, DTYPE_FINAL] as $oid) {
            $fmt = Banal_Setting::make_document($sv, $sv->conf->option_by_id($oid));
            if ($fmt->active || !$fmt->spec->is_banal_empty()) {
                $m[] = $fmt;
            }
        }
        $sv->append_oblist("format", $m, "doctype");
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 === "format/" && $si->name2 === "") {
            $doctype = $sv->reqstr("{$si->name}/doctype")
                ?? $sv->reqstr("{$si->name}/id") ?? "";
            if (($opt = self::doctype_option($sv->conf, $doctype))) {
                $sv->set_oldv($si, Banal_Setting::make_document($sv, $opt));
            } else {
                $sv->set_oldv($si, new Banal_Setting);
            }
        }
    }

    /** @param 'submission'|'final' $doctype
     * @param SettingValues $sv */
    static function print($doctype, $sv) {
        $opt = self::doctype_option($sv->conf, $doctype);
        $sv->append_oblist("format", [Banal_Setting::make_document($sv, $opt)]);
        $ctr = $sv->search_oblist("format", "id", $opt->json_key());

        $open = $sv->vstr("format/{$ctr}/active") > 0;
        $uropen = !in_array((string) $sv->vstr("format/{$ctr}/pagelimit"), ["", "any", "N/A"], true);
        $editable = $sv->editable("format/{$ctr}");
        echo Ht::hidden("has_format", 1),
            Ht::hidden("format/{$ctr}/id", $opt->json_key()),
            Ht::hidden("format/{$ctr}/doctype", $opt->json_key()),
            Ht::hidden("format/{$ctr}/only_if_active", 1);
        $sv->print_checkbox("format/{$ctr}/active", "PDF format checker<span class=\"fx\">:</span>", ["class" => "uich js-foldup", "group_class" => "form-g has-fold " . ($open ? "foldo" : "foldc"), "group_open" => true]);
        echo '<div class="f-mcol mt-3 fx"><div class="flex-grow-0">';
        $sv->print_entry_group("format/{$ctr}/papersize", "Paper size", [
            "horizontal" => true,
            "readonly" => !$editable,
            "hint" => "Examples: “letter”, <span class=\"nw\">“21cm x 28cm”,</span> <span class=\"nw\">“letter OR A4”</span>"
        ]);
        $sv->print_entry_group("format/{$ctr}/textblock", "Text block", [
            "horizontal" => true,
            "readonly" => !$editable,
            "hint" => "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”"
        ]);
        $sv->print_entry_group("format/{$ctr}/columns", "Columns", [
            "horizontal" => true,
            "readonly" => !$editable
        ]);
        echo '</div>';
        echo '<div class="flex-grow-0">';
        $sv->print_entry_group("format/{$ctr}/pagelimit", "Page limit", [
            "horizontal" => true,
            "class" => "uii uich js-settings-banal-pagelimit",
            "readonly" => !$editable
        ]);
        echo '<div class="entryi fx2"><label></label><div class="entry settings-banal-unlimitedref">';
        $sv->print_checkbox("format/{$ctr}/unlimitedref", "Unlimited reference pages", ["disabled" => !$uropen || !$editable, "label_class" => $uropen ? null : "dim"]);
        echo '</div></div>';
        echo '<div class="entryi fx2"><label></label><div class="entry">';
        $sv->print_checkbox("format/{$ctr}/appendix", "Allow appendix sections");
        echo '</div></div>';
        if ($sv->conf->opt("allowBanalWordlimit")) {
            $sv->print_entry_group("format/{$ctr}/wordlimit", "Word limit", ["horizontal" => true, "readonly" => !$editable]);
        }
        $sv->print_entry_group("format/{$ctr}/bodyfontsize", "Body font size", ["horizontal" => true, "control_after" => "&nbsp;pt", "readonly" => !$editable]);
        $sv->print_entry_group("format/{$ctr}/bodylineheight", "Line height", ["horizontal" => true, "control_after" => "&nbsp;pt", "readonly" => !$editable]);
        echo "</div></div></div>\n";
    }


    /** @return string */
    static private function cf_status(CheckFormat $cf) {
        if (!$cf->check_ok()) {
            return "failed";
        } else if ($cf->has_error()) {
            return "error";
        }
        return $cf->has_problem() ? "warning" : "ok";
    }

    /** @param SettingValues $sv */
    private function check_banal($sv) {
        if ($this->checked) {
            return;
        }
        $this->checked = true;
        $cf = new CheckFormat($sv->conf, CheckFormat::RUN_ALWAYS);
        $interesting_keys = ["papersize", "pagelimit", "textblock", "bodyfontsize", "bodylineheight"];
        $doc = DocumentInfo::make_content_file($sv->conf, SiteLoader::resolve("etc/sample.pdf"), "application/pdf");
        $cf->check_document($doc, "letter;2;;6.5inx9in;12;14");
        $s1 = self::cf_status($cf);
        $e1 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $e1_papersize = $cf->has_problem_at("papersize");
        $cf->check_document($doc, "a4;1;;3inx3in;14;15");
        $s2 = self::cf_status($cf);
        $e2 = join(",", array_intersect($cf->problem_fields(), $interesting_keys)) ? : "none";
        $want_e2 = join(",", $interesting_keys);
        if ($s1 !== "ok" || $e1 !== "none" || $s2 !== "error" || $e2 !== $want_e2) {
            $errors = "<div class=\"fx\"><table><tr><td>Analysis:&nbsp;</td><td>$s1 $e1 $s2 $e2 (expected ok none error $want_e2)</td></tr>"
                . "<tr><td class=\"nw\">Exit status:&nbsp;</td><td>" . htmlspecialchars((string) $cf->banal_run->status) . "</td></tr>";
            if (trim($cf->banal_run->stdout)) {
                $errors .= "<tr><td>Stdout:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_run->stdout) . "</pre></td></tr>";
            }
            if (trim($cf->banal_run->stderr)) {
                $errors .= "<tr><td>Stderr:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_run->stderr) . "</pre></td></tr>";
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

    private function _apply_req_papersize(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name));
        $bs->papersize = $s;
        $bs->spec->papersize = [];
        if (self::is_any_str($s)) {
            return;
        }
        foreach (preg_split('/\s*,\s*|\s+OR\s+/i', $s) as $ss) {
            if ($ss !== "" && ($d = FormatSpec::parse_dimen2($ss))) {
                $bs->spec->papersize[] = $d;
            } else if ($ss !== "") {
                $sv->error_at($si, "<0>Invalid paper size");
                return;
            }
        }
    }

    private function _apply_req_pagelimit(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name));
        $bs->pagelimit = $s;
        $bs->spec->pagelimit = null;
        if (self::is_any_str($s)) {
            return;
        }
        if (($sx = stoi($s) ?? -1) > 0) {
            $bs->spec->pagelimit = [0, $sx];
        } else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                   && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2]) {
            $bs->spec->pagelimit = [+$m[1], +$m[2]];
        } else {
            $sv->error_at($si, "<5>Requires a whole number greater than 0, or a page range such as <code>2-4</code>");
        }
    }

    private function _apply_req_unlimitedref(Si $si, SettingValues $sv, Banal_Setting $bs) {
        // applied after `pagelimit` (`parse_order` in settinginfo.json)
        $v = !!$sv->base_parse_req($si);
        $bs->unlimitedref = $v ? "1" : "";
        $bs->spec->unlimitedref = $v && $bs->spec->pagelimit ? true : null;
    }

    private function _apply_req_appendix(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $v = !!$sv->base_parse_req($si);
        $bs->appendix = $v;
        $bs->spec->noappendix = $v ? null : true;
    }

    private function _apply_req_wordlimit(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name));
        $bs->wordlimit = $s;
        $bs->spec->wordlimit = null;
        if (self::is_any_str($s)) {
            return;
        }
        if (($sx = stoi($s) ?? -1) >= 0) {
            $bs->spec->wordlimit = [0, $sx];
        } else if (preg_match('/\A(\d+)\s*(?:-|–)\s*(\d+)\z/', $s, $m)
                   && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2]) {
            $bs->spec->wordlimit = [+$m[1], +$m[2]];
        } else {
            $sv->error_at($si, "<5>Requires a whole number or a range such as <code>2-4</code>");
        }
    }

    private function _apply_req_columns(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name));
        $bs->columns = $s;
        $bs->spec->columns = 0;
        if (self::is_any_str($s)) {
            return;
        }
        if (($sx = stoi($s) ?? -1) >= 0) {
            $bs->spec->columns = $sx;
        } else {
            $sv->error_at($si, "<0>Requires a whole number");
        }
    }

    private function _apply_req_textblock(Si $si, SettingValues $sv, Banal_Setting $bs) {
        // applied after `papersize` (`parse_order` in settinginfo.json)
        $s = trim($sv->reqstr($si->name) ?? "");
        $bs->textblock = $s;
        $bs->spec->textblock = null;
        if (self::is_any_str($s)) {
            return;
        }
        // change margin specifications into text block measurements
        if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
            $s = $m[1];
            $papersize = $bs->spec->papersize;
            if (!$papersize || count($papersize) !== 1) {
                $sv->error_at("{$si->name0}{$si->name1}/papersize", "<0>You must specify a paper size as well as margins");
                $sv->error_at($si);
                return;
            }
            $ps = $papersize[0];
            if (strpos($s, "x") === false) {
                $s = preg_replace('/\s+(?=[\d.])/', 'x', trim($s));
                $css = 1;
            } else {
                $css = 0;
            }
            if (!($m = FormatSpec::parse_dimen($s))
                || (is_array($m) && count($m) > 4)) {
                $sv->error_at($si, "<0>Invalid margin definition");
                return;
            } else if (!is_array($m)) {
                $s = [$ps[0] - 2 * $m, $ps[1] - 2 * $m];
            } else if (count($m) == 2) {
                $s = [$ps[0] - 2 * $m[$css], $ps[1] - 2 * $m[1 - $css]];
            } else if (count($m) == 3) {
                $s = [$ps[0] - $m[$css] - $m[2 - $css], $ps[1] - $m[1 - $css] - $m[1 + $css]];
            } else {
                $s = [$ps[0] - $m[$css] - $m[2 + $css], $ps[1] - $m[1 - $css] - $m[3 - $css]];
            }
            $s = FormatSpec::unparse_dimen($s);
        }
        // check text block measurements
        if ($s && ($d = FormatSpec::parse_dimen2($s))) {
            $bs->spec->textblock = $d;
        } else {
            $sv->error_at($si, "<0>Invalid text block definition");
        }
    }

    private function _apply_req_bodyfontsize(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name) ?? "");
        $bs->bodyfontsize = $s;
        $bs->spec->bodyfontsize = null;
        if (self::is_any_str($s)) {
            return;
        }
        $bs->spec->bodyfontsize = FormatSpec::parse_range($s);
        if (!$bs->spec->bodyfontsize) {
            $sv->error_at($si, "<0>Requires a number greater than 0");
        }
    }

    private function _apply_req_bodylineheight(Si $si, SettingValues $sv, Banal_Setting $bs) {
        $s = trim($sv->reqstr($si->name) ?? "");
        $bs->bodylineheight = $s;
        $bs->spec->bodylineheight = null;
        if (self::is_any_str($s)) {
            return;
        }
        $bs->spec->bodylineheight = FormatSpec::parse_range($s);
        if (!$bs->spec->bodylineheight) {
            $sv->error_at($si, "<0>Requires a number greater than 0");
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "format") {
            foreach ($sv->oblist_nondeleted_keys("format") as $ctr) {
                $this->_apply_format_req($sv, $ctr);
            }
            return true;
        }
        if ($si->name0 !== "format/" || $si->name2 === "") {
            return false;
        }
        $bs = $sv->object_newv($si->name0 . $si->name1);
        if (!$bs || $si->name2 === "/active") {
            return false;
        }
        // ignore all components if inactive, but `only_if_active`
        if ($sv->reqstr_boolean("format/{$si->name1}/only_if_active")
            && $sv->has_req("format/{$si->name1}/active")
            && !$sv->reqstr_boolean("format/{$si->name1}/active")) {
            return true;
        }
        if ($si->name2 === "/papersize") {
            $this->_apply_req_papersize($si, $sv, $bs);
        } else if ($si->name2 === "/pagelimit") {
            $this->_apply_req_pagelimit($si, $sv, $bs);
        } else if ($si->name2 === "/unlimitedref") {
            $this->_apply_req_unlimitedref($si, $sv, $bs);
        } else if ($si->name2 === "/appendix") {
            $this->_apply_req_appendix($si, $sv, $bs);
        } else if ($si->name2 === "/wordlimit") {
            $this->_apply_req_wordlimit($si, $sv, $bs);
        } else if ($si->name2 === "/columns") {
            $this->_apply_req_columns($si, $sv, $bs);
        } else if ($si->name2 === "/textblock") {
            $this->_apply_req_textblock($si, $sv, $bs);
        } else if ($si->name2 === "/bodyfontsize") {
            $this->_apply_req_bodyfontsize($si, $sv, $bs);
        } else if ($si->name2 === "/bodylineheight") {
            $this->_apply_req_bodylineheight($si, $sv, $bs);
        } else if ($si->name2 === "/doctype") {
            // checked in `_apply_format_req`; `$bs->doctype` is canonical
        } else {
            return false;
        }
        return true;
    }

    /** @param SettingValues $sv
     * @param int $ctr */
    private function _apply_format_req($sv, $ctr) {
        // BANAL SETTINGS
        // option: extra settings
        // value: 0: off, no setting (may result in no setting row)
        //    -1: off; when setting was saved, there was an option spec
        //        supplied in conf/options.php; that option's banal constraints
        //        should be suppressed at runtime
        //    >0: time setting was last changed
        // data: setting

        $doctype = $sv->reqstr("format/{$ctr}/doctype")
            ?? $sv->reqstr("format/{$ctr}/id") ?? "";
        if ($doctype === "") {
            $sv->error_at("format/{$ctr}/doctype", "<0>Entry required");
            return;
        }
        $opt = $sv->conf->options()->find($doctype);
        if (!$opt) {
            $sv->error_at("format/{$ctr}/doctype", "<0>Unknown document type ‘{$doctype}’");
            return;
        }
        if ($opt->id !== DTYPE_SUBMISSION && $opt->id !== DTYPE_FINAL) {
            $sv->error_at("format/{$ctr}/doctype", "<0>Format checking is not supported for ‘{$opt->name}’");
            return;
        }
        // NB the database stores doctypes as option IDs
        $sid = $opt->id < 0 ? "m" . -$opt->id : (string) $opt->id;

        // parse member requests into `$bs->spec` (apply_req ignores
        // them when inactive with `only_if_active`)
        $bs = $sv->object_newv("format/{$ctr}");
        if ($sv->has_error_under("format/{$ctr}/")) {
            return;
        }

        if ($bs->active) {
            $this->check_banal($sv);
        }

        $opt_unparse = (new FormatSpec($sv->newv("fmtstore_o_{$sid}")))->unparse_banal();
        $unparse = $bs->spec->unparse();
        if ($unparse === $opt_unparse) {
            $unparse = "";
        }
        $sv->update("fmtstore_s_{$sid}", $unparse);

        if (!$bs->active) {
            $fs = new FormatSpec($sv->newv("fmtstore_o_{$sid}"));
            $sv->update("fmtstore_v_{$sid}", $fs->is_banal_empty() ? 0 : -1);
            return;
        }
        if ($unparse === "") {
            $sv->warning_at("format/{$ctr}/active", "<0>The format checker does nothing unless at least one constraint is enabled");
        }
        $old_unparse = (new FormatSpec($sv->oldv("fmtstore_s_{$sid}")))->unparse_banal();
        $old_storev = $sv->oldv("fmtstore_v_{$sid}");
        if ($old_unparse !== $unparse || $old_storev <= 0) {
            $sv->update("fmtstore_v_{$sid}", $unparse !== "" ? Conf::$now : 0);
        } else {
            $sv->update("fmtstore_v_{$sid}", $unparse === "" ? 0 : $old_storev);
        }
    }
}
