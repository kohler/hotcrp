<?php
// search/st_option.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class OptionMatcher {
    public $option;
    public $compar;
    public $value;
    public $kind;
    public $pregexes = null;
    public $match_null = false;

    function __construct($option, $compar, $value = null, $kind = 0) {
        if ($option->type === "checkbox" && $value === null)
            $value = 0;
        assert(($value !== null && !is_array($value)) || $compar === "=" || $compar === "!=");
        assert(!$kind || $value !== null);
        $this->option = $option;
        $this->compar = $compar;
        $this->value = $value;
        $this->kind = $kind;
        $this->match_null = false;
        if (!$this->kind) {
            if ($option->type === "checkbox"
                ? CountMatcher::compare(0, $this->compar, $this->value)
                : $this->compar === "=" && $this->value === null)
                $this->match_null = true;
        } else if ($this->kind === "attachment-count")
            $this->match_null = CountMatcher::compare(0, $this->compar, $this->value);
    }
    function table_matcher($col) {
        $q = "(select paperId from PaperOption where optionId=" . $this->option->id;
        if (!$this->kind && $this->value !== null) {
            $q .= " and value";
            if (is_array($this->value)) {
                if ($this->compar === "!=")
                    $q .= " not";
                $q .= " in (" . join(",", $this->value) . ")";
            } else
                $q .= $this->compar . $this->value;
        }
        $q .= " group by paperId)";
        if ($this->match_null)
            $col = "coalesce($col,Paper.paperId)";
        return [$q, $col];
    }
    function exec(PaperInfo $prow, Contact $user) {
        $ov = null;
        if ($user->can_view_paper_option($prow, $this->option))
            $ov = $prow->option($this->option->id);
        if (!$this->kind) {
            if (!$ov)
                return $this->match_null;
            else if ($this->value === null)
                return !$this->match_null;
            else if (is_array($this->value)) {
                $in = in_array($ov->value, $this->value);
                return $this->compar === "=" ? $in : !$in;
            } else
                return CountMatcher::compare($ov->value, $this->compar, $this->value);
        } else if ($this->kind === "attachment-count")
            return CountMatcher::compare($ov ? $ov->value_count() : 0,
                                         $this->compar, $this->value);
        if (!$ov)
            return false;
        if (!$this->pregexes)
            $this->pregexes = Text::star_text_pregexes($this->value);
        if ($this->kind === "text") {
            return Text::match_pregexes($this->pregexes, (string) $ov->data(), false);
        } else if ($this->kind === "attachment-name") {
            foreach ($ov->documents() as $doc)
                if (Text::match_pregexes($this->pregexes, $doc->filename, false))
                    return true;
        }
        return false;
    }
}

class Option_SearchTerm extends SearchTerm {
    private $om;

    function __construct(OptionMatcher $om) {
        parent::__construct("option");
        $this->om = $om;
    }
    static function parse_factory($keyword, Conf $conf, $kwfj, $m) {
        $f = $conf->find_all_fields($keyword);
        if (count($f) == 1 && $f[0] instanceof PaperOption)
            return (object) [
                "name" => $keyword, "parse_callback" => "Option_SearchTerm::parse",
                "has" => "yes"
            ];
        else
            return null;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if ($sword->kwdef->name !== "option")
            $word = $sword->kwdef->name . ":" . $word;
        $os = self::analyze($srch->conf, $word);
        foreach ($os->warn as $w)
            $srch->warn($w);
        if (!empty($os->os)) {
            $qz = array();
            foreach ($os->os as $oq)
                $qz[] = new Option_SearchTerm($oq);
            $t = SearchTerm::make_op("or", $qz);
            return $os->negate ? SearchTerm::make_not($t) : $t;
        } else
            return new False_SearchTerm;
    }
    static function analyze(Conf $conf, $word) {
        if (preg_match('/\A(.*?)([:#](?:[=!<>]=?|≠|≤|≥|)|[=!<>]=?|≠|≤|≥)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] === ":" || $m[2][0] === "#")
                $m[2] = substr($m[2], 1);
            $ocompar = CountMatcher::canonical_comparator($m[2]);
            $oval = strtolower(simplify_whitespace($m[3]));
        } else {
            $oname = $word;
            $ocompar = "=";
            $oval = "";
        }
        $oname = simplify_whitespace($oname);

        // match all options
        $qo = $warn = array();
        $option_failure = false;
        if (strcasecmp($oname, "none") === 0 || strcasecmp($oname, "any") === 0)
            $omatches = $conf->paper_opts->option_list();
        else
            $omatches = $conf->find_all_fields($oname, Conf::FSRCH_OPTION);
        // Conf::msg_debugt(var_export($omatches, true));
        if (!empty($omatches)) {
            foreach ($omatches as $o) {
                if ($o->has_selector()) {
                    $x = $o->parse_selector_search($oname, $ocompar, $oval);
                    if (is_string($x))
                        $warn[] = $x;
                    else
                        $qo[] = $x;
                } else {
                    if ($oval === "" || $oval === "yes")
                        $qo[] = new OptionMatcher($o, "!=", null);
                    else if ($oval === "no")
                        $qo[] = new OptionMatcher($o, "=", null);
                    else if ($o->type === "numeric") {
                        if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                            $qo[] = new OptionMatcher($o, $ocompar, $m[1]);
                        else
                            $warn[] = "Submission field “" . htmlspecialchars($o->title) . "” takes integer values.";
                    } else if ($o->type === "text") {
                        $qo[] = new OptionMatcher($o, "~=", $oval, "text");
                    } else if ($o->has_attachments()) {
                        if ($oval === "any")
                            $qo[] = new OptionMatcher($o, "!=", null);
                        else if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                            $qo[] = new OptionMatcher($o, $ocompar, $m[1], "attachment-count");
                        else
                            $qo[] = new OptionMatcher($o, "~=", $oval, "attachment-name");
                    }
                }
            }
        } else if (($ocompar === "=" || $ocompar === "!=") && $oval === "")
            foreach ($conf->paper_opts->option_list() as $o)
                if ($o->has_selector()) {
                    foreach (Text::simple_search($oname, $o->selector) as $xval => $text)
                        $qo[] = new OptionMatcher($o, $ocompar, $xval);
                }

        if (empty($qo) && empty($warn))
            $warn[] = "“" . htmlspecialchars($word) . "” doesn’t match a submission field.";
        return (object) array("os" => $qo, "warn" => $warn, "negate" => strcasecmp($oname, "none") === 0, "value_word" => $oval);
    }

    function debug_json() {
        $om = $this->om;
        return [$this->type, $om->option->search_keyword(), $om->kind, $om->compar, $om->value];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Option_" . count($sqi->tables);
        $tm = $this->om->table_matcher("$thistab.paperId");
        $sqi->add_table($thistab, ["left join", $tm[0]]);
        return $tm[1];
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->om->exec($row, $srch->user);
    }
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        if ($this->om->kind)
            return null;
        else
            return (object) ["type" => "option", "id" => $this->om->option->id, "compar" => $this->om->compar, "value" => $this->om->value];
    }
}
