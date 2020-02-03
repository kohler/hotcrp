<?php
// search/st_option.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
        assert(($value !== null && !is_array($value))
               || $compar === "=" || $compar === "!=");
        assert(!$kind || $value !== null);
        $this->option = $option;
        $this->compar = $compar;
        $this->value = $value;
        $this->kind = $kind;
        $this->match_null = $this->compar === "=" && $this->value === null;
        if (!$this->match_null
            && ((!$this->kind && $option->type === "checkbox")
                || $this->kind === "attachment-count")
            && CountMatcher::compare(0, $this->compar, $this->value)) {
            $this->match_null = true;
        }
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
        $coalesce = $this->match_null ? "Paper.paperId" : "0";
        return [$q, "coalesce($col,$coalesce)"];
    }
    function exec(PaperInfo $prow, Contact $user) {
        $ov = null;
        if ($user->can_view_option($prow, $this->option)) {
            $ov = $prow->option($this->option);
        }

        if (!$ov) {
            return $this->match_null;
        } else if (!$this->kind) {
            if ($this->value === null) {
                return !$this->match_null;
            } else if (is_array($this->value)) {
                $in = in_array($ov->value, $this->value);
                return $this->compar === "=" ? $in : !$in;
            } else {
                return CountMatcher::compare($ov->value, $this->compar, $this->value);
            }
        } else if ($this->kind === "attachment-count") {
            return CountMatcher::compare($ov->value_count(), $this->compar, $this->value);
        }

        if (!$this->pregexes) {
            $this->pregexes = Text::star_text_pregexes($this->value);
        }
        $in = false;
        if ($this->kind === "text") {
            $in = Text::match_pregexes($this->pregexes, (string) $ov->data(), false);
        } else if ($this->kind === "attachment-name") {
            foreach ($ov->documents() as $doc) {
                if (Text::match_pregexes($this->pregexes, $doc->filename, false)) {
                    $in = true;
                    break;
                }
            }
        }
        return $this->compar === "=" ? $in : !$in;
    }
}

class OptionMatcherSet {
    public $os = [];
    public $warnings = [];
    public $compar;
    public $vword;
    public $quoted;
    public $negated = false;
}

class Option_SearchTerm extends SearchTerm {
    private $om;

    function __construct(OptionMatcher $om) {
        parent::__construct("option");
        $this->om = $om;
    }
    static function parse_factory($keyword, $user, $kwfj, $m) {
        $f = $user->conf->find_all_fields($keyword);
        if (count($f) === 1 && $f[0] instanceof PaperOption)
            return (object) [
                "name" => $keyword,
                "parse_callback" => "Option_SearchTerm::parse",
                "has" => "any"
            ];
        else
            return null;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if ($sword->kwdef->name !== "option")
            $word = $sword->kwdef->name . ":" . $word;
        $os = self::analyze($srch->conf, $word, $sword->quoted);
        foreach ($os->warnings as $w)
            $srch->warn($w);
        if (!empty($os->os)) {
            $qz = array();
            foreach ($os->os as $oq)
                $qz[] = new Option_SearchTerm($oq);
            $t = SearchTerm::make_op("or", $qz);
            return $os->negated ? SearchTerm::make_not($t) : $t;
        } else
            return new False_SearchTerm;
    }
    static function analyze(Conf $conf, $word, $quoted = false) {
        $oms = new OptionMatcherSet;
        if (preg_match('/\A(.*?)([:#](?:[=!<>]=?|≠|≤|≥|)|[=!<>]=?|≠|≤|≥)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] === ":" || $m[2][0] === "#")
                $m[2] = substr($m[2], 1);
            $oms->compar = CountMatcher::canonical_comparator($m[2]);
            $oms->vword = simplify_whitespace($m[3]);
        } else {
            $oname = $word;
            $oms->compar = "=";
            $oms->vword = "";
        }
        $oms->quoted = $quoted;
        $oname = simplify_whitespace($oname);

        // match all options
        $option_failure = false;
        if (strcasecmp($oname, "none") === 0
            || strcasecmp($oname, "any") === 0) {
            $omatches = $conf->paper_opts->option_list();
        } else {
            $omatches = $conf->find_all_fields($oname, Conf::FSRCH_OPTION);
        }
        $isany = false;
        if (!$quoted
            && $oms->compar === "="
            && ($oms->vword === ""
                || strcasecmp($oms->vword, "any") === 0
                || strcasecmp($oms->vword, "none") === 0)) {
            $isany = true;
            if (strcasecmp($oms->vword, "none") === 0) {
                $oms->negated = true;
            }
        }
        // Conf::msg_debugt(var_export($omatches, true));
        foreach ($omatches as $o) {
            if ($isany) {
                $oms->os[] = new OptionMatcher($o, "!=", null);
            } else if (!$o->parse_search($oms)) {
                $oms->warnings[] = "Bad search “" . htmlspecialchars($oms->vword) . "” for submission field “" . $o->title_html() . "”.";
            }
        }

        if (empty($oms->os) && empty($oms->warnings)) {
            $oms->warnings[] = "“" . htmlspecialchars($word) . "” doesn’t match a submission field.";
        }
        return $oms;
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        if ($this->om->kind) {
            return null;
        } else if (!$srch->user->can_view_option($row, $this->om->option)) {
            return false;
        } else {
            return (object) [
                "type" => "option", "id" => $this->om->option->id,
                "compar" => $this->om->compar, "value" => $this->om->value
            ];
        }
    }
}
