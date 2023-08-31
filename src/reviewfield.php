<?php
// reviewfield.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

// JSON schema for settings["review_form"]:
// [{"id":FIELDID,"name":NAME,"description":DESCRIPTION,"order":ORDER,
//   "display_space":ROWS,"visibility":VISIBILITY,
//   "values":[DESCRIPTION,...],["start":LEVELCHAR | "symbols":[WORD,...]]},...]

class ReviewFieldInfo {
    /** @var non-empty-string
     * @readonly */
    public $short_id;
    /** @var bool
     * @readonly */
    public $is_sfield;
    /** @var ?non-empty-string
     * @readonly */
    public $main_storage;
    /** @var ?non-empty-string
     * @readonly */
    public $json_storage;

    // see also Signature properties in PaperInfo
    /** @var list<?non-empty-string>
     * @readonly */
    static private $new_sfields = [
        "overAllMerit", "reviewerQualification", "novelty", "technicalMerit",
        "interestToCommunity", "longevity", "grammar", "likelyPresentation",
        "suitableForShort", "potential", "fixability"
    ];
    /** @var array<string,ReviewFieldInfo> */
    static private $field_info_map = [];

    /** @param string $short_id
     * @param bool $is_sfield
     * @param ?non-empty-string $main_storage
     * @param ?non-empty-string $json_storage
     * @phan-assert non-empty-string $short_id */
    function __construct($short_id, $is_sfield, $main_storage, $json_storage) {
        $this->short_id = $short_id;
        $this->is_sfield = $is_sfield;
        $this->main_storage = $main_storage;
        $this->json_storage = $json_storage;
    }

    /** @param Conf $conf
     * @param string $id
     * @return ?ReviewFieldInfo */
    static function find($conf, $id) {
        $m = self::$field_info_map[$id] ?? null;
        if (!$m && !array_key_exists($id, self::$field_info_map)) {
            $sv = $conf->sversion;
            if (strlen($id) > 3
                && ($n = array_search($id, self::$new_sfields)) !== false) {
                $id = sprintf("s%02d", $n + 1);
            }
            if (strlen($id) === 3
                && ($id[0] === "s" || $id[0] === "t")
                && ($d1 = ord($id[1])) >= 48
                && $d1 <= 57
                && ($d2 = ord($id[2])) >= 48
                && $d2 <= 57) {
                $n = ($d1 - 48) * 10 + $d2 - 48;
                if ($id[0] === "s" && $n < 12) {
                    $storage = $sv >= 260 ? $id : self::$new_sfields[$n - 1];
                    $m = new ReviewFieldInfo($id, true, $storage, null);
                    self::$field_info_map[self::$new_sfields[$n - 1]] = $m;
                } else {
                    $m = new ReviewFieldInfo($id, $id[0] === "s", null, $id);
                }
            }
            self::$field_info_map[$id] = $m;
        }
        return $m;
    }
}

abstract class ReviewField implements JsonSerializable {
    /** @var non-empty-string
     * @readonly */
    public $short_id;
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var string */
    public $name;
    /** @var string */
    public $name_html;
    /** @var string */
    public $type;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $_search_keyword;
    /** @var int */
    public $view_score;
    /** @var ?int */
    public $order;
    /** @var int */
    public $round_mask = 0;
    /** @var ?string */
    public $exists_if;
    /** @var ?SearchTerm */
    private $_exists_search;
    /** @var bool */
    private $_need_exists_search;
    /** @var bool */
    public $required = false;
    /** @var ?non-empty-string
     * @readonly */
    public $main_storage;
    /** @var ?non-empty-string
     * @readonly */
    public $json_storage;
    /** @var bool
     * @readonly */
    public $is_sfield;

    static private $view_score_map = [
        "secret" => VIEWSCORE_ADMINONLY,
        "admin" => VIEWSCORE_REVIEWERONLY,
        "pconly" => VIEWSCORE_PC,
        "re" => VIEWSCORE_REVIEWER, "pc" => VIEWSCORE_REVIEWER,
        "audec" => VIEWSCORE_AUTHORDEC, "authordec" => VIEWSCORE_AUTHORDEC,
        "au" => VIEWSCORE_AUTHOR, "author" => VIEWSCORE_AUTHOR
    ];
    // Hard-code the database's `view_score` values as of January 2016
    static private $view_score_upgrade_map = [
        -2 => "secret", -1 => "admin", 0 => "re", 1 => "au"
    ];
    static private $view_score_rmap = [
        VIEWSCORE_ADMINONLY => "secret",
        VIEWSCORE_REVIEWERONLY => "admin",
        VIEWSCORE_PC => "pconly",
        VIEWSCORE_REVIEWER => "re",
        VIEWSCORE_AUTHORDEC => "audec",
        VIEWSCORE_AUTHOR => "au"
    ];
    /** @var ?Score_ReviewField */
    static public $expertise_field;

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        $this->short_id = $finfo->short_id;
        $this->main_storage = $finfo->main_storage;
        $this->json_storage = $finfo->json_storage;
        $this->is_sfield = $finfo->is_sfield;
        $this->conf = $conf;

        $this->name = $j->name ?? "";
        $this->name_html = htmlspecialchars($this->name);
        $this->type = $j->type ?? ($this->is_sfield ? "radio" : "text");
        $this->description = $j->description ?? "";
        $vis = $j->visibility ?? null;
        if ($vis === null /* XXX backward compat */) {
            $vis = $j->view_score ?? null;
            if (is_int($vis)) {
                $vis = self::$view_score_upgrade_map[$vis];
            }
        }
        $this->view_score = VIEWSCORE_REVIEWER;
        if (is_string($vis) && isset(self::$view_score_map[$vis])) {
            $this->view_score = self::$view_score_map[$vis];
        }
        $this->order = $j->order ?? $j->position /* XXX */ ?? null;
        if ($this->order !== null && $this->order < 0) {
            $this->order = 0;
        }
        $this->round_mask = $j->round_mask ?? 0;
        if ($this->exists_if !== ($j->exists_if ?? null)) {
            $this->exists_if = $j->exists_if ?? null;
            $this->_exists_search = null;
            $this->_need_exists_search = ($this->exists_if ?? "") !== "";
        }
        $this->required = !!($j->required ?? false);
    }

    /** @param ReviewFieldInfo $rfi
     * @return ReviewField */
    static function make_json(Conf $conf, $rfi, $j) {
        if ($rfi->is_sfield) {
            $t = $j->type ?? "radio";
            if ($t === "checkbox") {
                return new Checkbox_ReviewField($conf, $rfi, $j);
            } else if ($t === "checkboxes") {
                return new Checkboxes_ReviewField($conf, $rfi, $j);
            } else {
                return new Score_ReviewField($conf, $rfi, $j);
            }
        } else {
            return new Text_ReviewField($conf, $rfi, $j);
        }
    }

    /** @param ReviewField $a
     * @param ReviewField $b
     * @return int */
    static function order_compare($a, $b) {
        if (!$a->order !== !$b->order) {
            return $a->order ? -1 : 1;
        } else if ($a->order !== $b->order) {
            return $a->order < $b->order ? -1 : 1;
        } else {
            return strcmp($a->short_id, $b->short_id);
        }
    }

    /** @param string $s
     * @return string */
    static function clean_name($s) {
        while ($s !== ""
               && $s[strlen($s) - 1] === ")"
               && ($lparen = strrpos($s, "(")) !== false
               && preg_match('/\G\((?:hidden|shown|invisible|visible|secret|private|pc only)(?:(?: only)?+(?: from| to)?+(?: the)?+| field)(?:| authors?+| chairs?+| administrators?+)(?:| until decision| and external reviewers| only)[.?!]?\)\z/i', $s, $m, 0, $lparen)) {
            $s = rtrim(substr($s, 0, $lparen));
        }
        return $s;
    }

    /** @return Score_ReviewField */
    static function make_expertise(Conf $conf) {
        if (self::$expertise_field === null) {
            $rfi = new ReviewFieldInfo("s98", true, null, "s98");
            self::$expertise_field = new Score_ReviewField($conf, $rfi, json_decode('{"name":"Expertise","symbols":["X","Y","Z"],"flip":true,"scheme":"none"}'));
        }
        return self::$expertise_field;
    }

    /** @return string */
    function unparse_round_mask() {
        if ($this->round_mask) {
            $rs = [];
            foreach ($this->conf->round_list() as $i => $rname) {
                if ($this->round_mask & (1 << $i))
                    $rs[] = $i ? "round:{$rname}" : "round:unnamed";
            }
            natcasesort($rs);
            return join(" OR ", $rs);
        } else {
            return "";
        }
    }

    const UJ_EXPORT = 0;
    const UJ_TEMPLATE = 1;
    const UJ_STORAGE = 2;

    /** @param 0|1|2 $style
     * @return object */
    function export_json($style) {
        $j = (object) [];
        if ($style > 0) {
            $j->id = $this->short_id;
        }
        if ($style !== 2) {
            $j->uid = $this->uid();
        }
        $j->name = $this->name;
        $j->type = $this->type;
        if ($this->description) {
            $j->description = $this->description;
        }
        if ($this->order) {
            $j->order = $this->order;
        }
        $j->visibility = $this->unparse_visibility();
        if ($this->required) {
            $j->required = true;
        }
        $exists_if = $this->exists_if;
        if ($exists_if !== null && $style === self::UJ_STORAGE) {
            if (($term = $this->exists_term())) {
                list($this->round_mask, $other) = Review_SearchTerm::term_round_mask($term);
                $exists_if = $other ? $exists_if : null;
            } else {
                $exists_if = null;
            }
        }
        if ($exists_if !== null) {
            $j->exists_if = $exists_if;
        } else if ($this->round_mask !== 0 && $style !== self::UJ_STORAGE) {
            $j->exists_if = $this->unparse_round_mask();
        }
        if ($this->round_mask !== 0 && $style === self::UJ_STORAGE) {
            $j->round_mask = $this->round_mask;
        }
        return $j;
    }

    /** @return Rf_Setting */
    function export_setting() {
        $rfs = new Rf_Setting;
        $rfs->id = $this->short_id;
        $rfs->type = $this->type;
        $rfs->name = $this->name;
        $rfs->order = $this->order;
        $rfs->description = $this->description;
        $rfs->visibility = $this->unparse_visibility();
        $rfs->required = $this->required;
        $rm = $this->round_mask;
        if ($this->exists_if || ($rm !== 0 && ($rm & ($rm - 1)) !== 0)) {
            $rfs->presence = "custom";
            $rfs->exists_if = $this->exists_if ?? $this->unparse_round_mask();
        } else if ($rm !== 0) {
            $rfs->presence = $rfs->exists_if = $this->unparse_round_mask();
        } else {
            $rfs->presence = $rfs->exists_if = "all";
        }
        return $rfs;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->export_json(self::UJ_EXPORT);
    }

    /** @return string */
    function unparse_visibility() {
        return self::$view_score_rmap[$this->view_score] ?? (string) $this->view_score;
    }

    /** @param int $view_score
     * @return string */
    static function visibility_description($view_score) {
        if ($view_score >= VIEWSCORE_AUTHOR) {
            return "";
        } else if ($view_score < VIEWSCORE_REVIEWERONLY) {
            return "secret";
        } else if ($view_score < VIEWSCORE_PC) {
            return "shown only to administrators";
        } else if ($view_score < VIEWSCORE_REVIEWER) {
            return "hidden from authors and external reviewers";
        } else if ($view_score < VIEWSCORE_AUTHORDEC) {
            return "hidden from authors";
        } else {
            return "hidden from authors until decision";
        }
    }


    /** @return ?string */
    function exists_condition() {
        if ($this->exists_if !== null) {
            return $this->exists_if;
        } else if ($this->round_mask !== 0) {
            return $this->unparse_round_mask();
        } else {
            return null;
        }
    }

    /** @return ?SearchTerm */
    private function exists_term() {
        $st = (new PaperSearch($this->conf->root_user(), $this->exists_if ?? ""))->full_term();
        return $st instanceof True_SearchTerm ? null : $st;
    }

    /** @return bool */
    function test_exists(ReviewInfo $rrow) {
        if ($this->_need_exists_search) {
            $this->_exists_search = $this->exists_term();
            $this->_need_exists_search = false;
        }
        return (!$this->round_mask || ($this->round_mask & (1 << $rrow->reviewRound)) !== 0)
            && (!$this->_exists_search || $this->_exists_search->test($rrow->prow, $rrow));
    }

    /** @return bool */
    function include_word_count() {
        return false;
    }

    /** @return string */
    function search_keyword() {
        if ($this->_search_keyword === null) {
            $this->conf->abbrev_matcher();
            assert($this->_search_keyword !== null);
        }
        return $this->_search_keyword;
    }

    /** @return ?string */
    function abbreviation1() {
        $e = new AbbreviationEntry($this->name, $this, Conf::MFLAG_REVIEW);
        return $this->conf->abbrev_matcher()->find_entry_keyword($e, AbbreviationMatcher::KW_UNDERSCORE);
    }

    /** @return string */
    function web_abbreviation() {
        return '<span class="need-tooltip" aria-label="' . $this->name_html
            . '" data-tooltip-anchor="s">' . htmlspecialchars($this->search_keyword()) . "</span>";
    }

    /** @return string */
    function uid() {
        return $this->search_keyword();
    }

    /** @param ?int|?string $fval
     * @return bool */
    abstract function value_present($fval);

    /** @param ?int|?string $fval
     * @return ?int|?string */
    function value_clean_storage($fval) {
        return $fval;
    }

    /** @param int|float|string $fval
     * @return string */
    abstract function unparse_value($fval);

    /** @deprecated */
    function unparse($fval) {
        return $this->unparse_value($fval);
    }

    /** @param ?int|?float|?string $fval
     * @return mixed */
    abstract function unparse_json($fval);

    /** @param int|float|string $fval
     * @param ?string $real_format
     * @return string */
    function unparse_span_html($fval, $real_format = null) {
        return "";
    }

    /** @param int|float|string $fval
     * @return string */
    function unparse_search($fval) {
        return "";
    }

    const VALUE_NONE = 0;
    const VALUE_SC = 1;
    /** @deprecated */
    function value_unparse($fval, $flags = 0, $real_format = null) {
        return $flags & self::VALUE_SC ? $this->unparse_span_html($fval, $real_format) : $this->unparse_value($fval);
    }

    /** @param Qrequest $qreq
     * @param string $key
     * @return ?string */
    function extract_qreq($qreq, $key) {
        return $qreq[$key];
    }

    /** @param string $s
     * @return null|int|string|false */
    abstract function parse($s);

    /** @param mixed $j
     * @return null|int|string|false */
    abstract function parse_json($j);

    /** @param ?string $id
     * @param ?string $label_for
     * @param ReviewValues $rvalues
     * @param ?array{name_html?:string,label_class?:string} $args */
    protected function print_web_edit_open($id, $label_for, $rvalues, $args = null) {
        echo '<div class="rf rfe" data-rf="', $this->uid(),
            '"><h3 class="',
            $rvalues->control_class($this->short_id, "rfehead");
        if ($id !== null) {
            echo '" id="', $id;
        }
        echo '"><label class="', $args["label_class"] ?? "revfn";
        if ($this->required) {
            echo ' field-required';
        }
        if ($label_for) {
            echo '" for="', $label_for;
        }
        echo '">', $args["name_html"] ?? $this->name_html, '</label>';
        if (($rd = self::visibility_description($this->view_score)) !== "") {
            echo '<div class="field-visibility">(', $rd, ')</div>';
        }
        echo '</h3>', $rvalues->feedback_html_at($this->short_id);
        if ($this->description) {
            echo '<div class="field-d">', $this->description, "</div>";
        }
    }

    /** @param int|string $fval
     * @param ?string $reqstr
     * @param ReviewValues $rvalues
     * @param array{format:?TextFormat} $args */
    abstract function print_web_edit($fval, $reqstr, $rvalues, $args);

    /** @param list<string> &$t
     * @param array{flowed:bool} $args */
    protected function unparse_text_field_header(&$t, $args) {
        $t[] = "\n";
        if (strlen($this->name) > 75) {
            $t[] = prefix_word_wrap("", $this->name, 0, 75, $args["flowed"]);
            $t[] = "\n";
            $t[] = str_repeat("-", 75);
            $t[] = "\n";
        } else {
            $t[] = "{$this->name}\n";
            $t[] = str_repeat("-", UnicodeHelper::utf8_glyphlen($this->name));
            $t[] = "\n";
        }
    }

    /** @param list<string> &$t
     * @param int|string $fval
     * @param array{flowed:bool} $args */
    abstract function unparse_text_field(&$t, $fval, $args);

    /** @param list<string> &$t */
    protected function unparse_offline_field_header(&$t, $args) {
        $t[] = prefix_word_wrap("==*== ", $this->name, "==*==    ");
        if (($rd = self::visibility_description($this->view_score)) !== "") {
            $t[] = "==-== ({$rd})\n";
        }
        if (($args["include_presence"] ?? false)
            && ($this->exists_if || $this->round_mask)) {
            $explanation = $this->exists_if ?? $this->unparse_round_mask();
            if (preg_match('/\Around:[a-zA-Z][-_a-zA-Z0-9]*\z/', $explanation)) {
                $t[] = "==-== (present on " . substr($explanation, 6) . " reviews)\n";
            } else {
                $t[] = "==-== (present on reviews matching `{$explanation}`)\n";
            }
        }
        if ($this->description) {
            $d = cleannl($this->description);
            if (strpbrk($d, "&<") !== false) {
                $d = Text::html_to_text($d);
            }
            $t[] = prefix_word_wrap("==-==    ", trim($d), "==-==    ");
        }
    }

    /** @param list<string> &$t
     * @param ?int|?string $fval
     * @param array{format:?TextFormat,include_presence:bool} $args */
    abstract function unparse_offline(&$t, $fval, $args);

    /** @param int $context
     * @return list<SearchExample> */
    function search_examples(Contact $viewer, $context) {
        return [];
    }

    /** @return ?ReviewFieldSearch */
    function parse_search(SearchWord $sword, ReviewSearchMatcher $rsm, PaperSearch $srch) {
        return null;
    }
}


abstract class Discrete_ReviewField extends ReviewField {
    /** @var string
     * @readonly */
    public $scheme = "sv";

    // color schemes; NB keys must be URL-safe
    /** @var array<string,list>
     * @readonly */
    static public $scheme_info = [
        "sv" => [0, 9, "svr"], "svr" => [1, 9, "sv"],
        "bupu" => [0, 9, "pubu"], "pubu" => [1, 9, "bupu"],
        "rdpk" => [1, 9, "pkrd"], "pkrd" => [0, 9, "rdpk"],
        "viridisr" => [1, 9, "viridis"], "viridis" => [0, 9, "viridisr"],
        "orbu" => [0, 9, "buor"], "buor" => [1, 9, "orbu"],
        "turbo" => [0, 9, "turbor"], "turbor" => [1, 9, "turbo"],
        "catx" => [2, 10, null], "none" => [2, 1, null]
    ];

    /** @var array<string,string>
     * @readonly */
    static public $scheme_alias = [
        "publ" => "pubu", "blpu" => "bupu"
    ];

    const FLAG_NUMERIC = 1;
    const FLAG_ALPHA = 2;
    const FLAG_SINGLE_CHAR = 4;
    const FLAG_DEFAULT_SYMBOLS = 8;


    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        assert($finfo->is_sfield);
        parent::__construct($conf, $finfo, $j);

        if (($sch = $j->scheme ?? null) !== null) {
            if (isset(self::$scheme_info[$sch])) {
                $this->scheme = $sch;
            } else {
                $this->scheme = self::$scheme_alias[$sch] ?? null;
            }
        }
    }

    function value_present($fval) {
        // assert(is_int($fval)); <- should hold
        return ($fval ?? 0) > 0;
    }

    /** @param string $scheme
     * @param int|float $fval
     * @param int $n
     * @param bool $flip
     * @return string */
    static function scheme_value_class($scheme, $fval, $n, $flip) {
        if ($fval < 0.8) {
            return "sv";
        }
        list($schfl, $nsch, $schrev) = Discrete_ReviewField::$scheme_info[$scheme];
        $sclass = ($schfl & 1) !== 0 ? $schrev : $scheme;
        $schflip = $flip !== (($schfl & 1) !== 0);
        if ($n <= 1) {
            $x = $schflip ? 1 : $nsch;
        } else {
            if ($schflip) {
                $fval = $n + 1 - $fval;
            }
            if (($schfl & 2) !== 0) {
                $x = (int) round($fval - 1) % $nsch + 1;
            } else {
                $x = (int) round(($fval - 1) * ($nsch - 1) / ($n - 1)) + 1;
            }
        }
        if ($sclass === "sv") {
            return "sv sv{$x}";
        } else {
            return "sv sv-{$sclass}{$x}";
        }
    }

    function export_json($style) {
        $j = parent::export_json($style);
        if ($this->scheme !== "sv") {
            $j->scheme = $this->scheme;
        }
        return $j;
    }

    function export_setting() {
        $rfs = parent::export_setting();
        $rfs->scheme = $this->scheme;
        return $rfs;
    }

    /** @param int|float $fval
     * @param ?string $real_format
     * @return string */
    abstract function unparse_computed($fval, $real_format = null);

    const GRAPH_STACK = 1;
    const GRAPH_PROPORTIONS = 2;
    const GRAPH_STACK_REQUIRED = 3;

    /** @param ScoreInfo $sci
     * @param 1|2|3 $style
     * @return string */
    abstract function unparse_graph($sci, $style);

    /** @param array<int,int> $fmap
     * @param int $fval
     * @return ?int */
    function renumber_value($fmap, $fval) {
        return $fval;
    }
}


abstract class DiscreteValues_ReviewField extends Discrete_ReviewField {
    /** @var list<string> */
    protected $values;
    /** @var list<int|string> */
    protected $symbols = [];
    // Symbols must be URL-safe, HTML-safe, no punctuation or reserved words
    /** @var ?list<int> */
    protected $ids;
    /** @var int
     * @readonly */
    protected $flags = 0;
    /** @var bool
     * @readonly */
    public $flip = false;

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        parent::__construct($conf, $finfo, $j);

        if (isset($j->symbols) && !isset($j->values)) {
            $this->values = array_fill(0, count($j->symbols), "");
        } else {
            $this->values = $j->values ?? $j->options /* XXX */ ?? [];
        }
        $nvalues = count($this->values);
        if (isset($j->symbols) && count($j->symbols) === $nvalues) {
            $this->symbols = $j->symbols;
            $this->flip = $this->flip ?? false;
            $this->flags = self::analyze_symbols($this->symbols, $this->flip);
        } else if ($nvalues === 0) {
            $this->flags = self::FLAG_NUMERIC | self::FLAG_DEFAULT_SYMBOLS;
        } else {
            $ch = $j->start ?? $j->option_letter ?? null;
            if ($ch && is_string($ch) && ctype_upper($ch) && strlen($ch) === 1) {
                $chx = chr(ord($ch) + $nvalues - 1);
                $this->symbols = range($chx, $ch);
                $this->flip = true;
                $this->flags = self::FLAG_SINGLE_CHAR | self::FLAG_ALPHA | self::FLAG_DEFAULT_SYMBOLS;
            } else {
                $this->symbols = range(1, $nvalues);
                $this->flags = self::FLAG_NUMERIC | self::FLAG_DEFAULT_SYMBOLS;
            }
        }
        if (isset($j->ids) && count($j->ids) === $nvalues) {
            $this->ids = $j->ids;
        }
    }

    /** @param list<int|string> $symbols
     * @param bool $flip
     * @return int */
    static function analyze_symbols($symbols, $flip) {
        if (empty($symbols)) {
            return self::FLAG_NUMERIC | self::FLAG_DEFAULT_SYMBOLS;
        }
        if ($symbols[0] === 1 && !$flip) {
            $f = self::FLAG_NUMERIC | self::FLAG_DEFAULT_SYMBOLS;
        } else if (is_string($symbols[0]) && ctype_upper($symbols[0])) {
            $f = self::FLAG_ALPHA | self::FLAG_SINGLE_CHAR | ($flip ? self::FLAG_DEFAULT_SYMBOLS : 0);
        } else {
            $f = self::FLAG_ALPHA | self::FLAG_SINGLE_CHAR;
        }
        foreach ($symbols as $i => $sym) {
            if (($f & self::FLAG_NUMERIC) !== 0
                && $sym !== $i + 1) {
                return 0;
            }
            if (($f & self::FLAG_ALPHA) !== 0
                && (is_int($sym) || strlen($sym) !== 1 || !ctype_alpha($sym))) {
                $f &= ~(self::FLAG_ALPHA | self::FLAG_DEFAULT_SYMBOLS);
            }
            if (($f & self::FLAG_SINGLE_CHAR) !== 0
                && (is_int($sym)
                    || (strlen($sym) !== 1
                        && UnicodeHelper::utf8_glyphlen((string) $sym) !== 1))) {
                $f &= ~self::FLAG_SINGLE_CHAR;
            }
            if (($f & self::FLAG_DEFAULT_SYMBOLS) !== 0
                && $flip
                && $sym !== chr(ord($symbols[0]) - $i)) {
                $f &= ~self::FLAG_DEFAULT_SYMBOLS;
            }
        }
        return $f;
    }

    /** @return int */
    function nvalues() {
        return count($this->values);
    }

    /** @return list<int|string> */
    function symbols() {
        return $this->symbols;
    }

    /** @return list<string> */
    function values() {
        return $this->values;
    }

    /** @return list<int|string> */
    function ordered_symbols() {
        return $this->flip ? array_reverse($this->symbols) : $this->symbols;
    }

    /** @return list<string> */
    function ordered_values() {
        return $this->flip ? array_reverse($this->values) : $this->values;
    }

    /** @return list<int> */
    function ids() {
        return $this->ids ?? range(1, count($this->values));
    }

    /** @return bool */
    function is_numeric() {
        return ($this->flags & self::FLAG_NUMERIC) !== 0;
    }

    /** @return bool */
    function is_alpha() {
        return ($this->flags & self::FLAG_ALPHA) !== 0;
    }

    /** @return bool */
    function is_single_character() {
        return ($this->flags & self::FLAG_SINGLE_CHAR) !== 0;
    }

    /** @return bool */
    function flip_relation() {
        return ($this->flags & (self::FLAG_ALPHA | self::FLAG_DEFAULT_SYMBOLS)) === (self::FLAG_ALPHA | self::FLAG_DEFAULT_SYMBOLS)
            && $this->flip === !$this->conf->opt("smartScoreCompare");
    }

    /** @param int|string $x
     * @return ?int */
    function find_symbol($x) {
        foreach ($this->symbols as $i => $sym) {
            if (strcasecmp($x, $sym) === 0)
                return $i + 1;
        }
        if (strcasecmp($x, "none") === 0
            || strcasecmp($x, "n/a") === 0
            || (($this->flags & self::FLAG_NUMERIC) !== 0 && $x === "0")) {
            return 0;
        }
        return null;
    }

    function export_json($style) {
        $j = parent::export_json($style);
        $j->values = $this->values;
        if (!empty($this->ids)
            && ($style !== self::UJ_STORAGE
                || $this->ids !== range(1, count($this->values)))) {
            $j->ids = $this->ids;
        }
        if (($this->flags & self::FLAG_DEFAULT_SYMBOLS) === 0) {
            $j->symbols = $this->symbols;
        } else if (($this->flags & self::FLAG_ALPHA) !== 0) {
            $j->start = $this->symbols[count($this->symbols) - 1];
        }
        if ($this->flip) {
            $j->flip = true;
        }
        return $j;
    }

    function export_setting() {
        $rfs = parent::export_setting();
        $n = count($this->values);
        $rfs->values = $this->values;
        $rfs->ids = $this->ids();
        if (($this->flags & self::FLAG_NUMERIC) === 0) {
            $rfs->start = $this->symbols[$n - 1];
        } else {
            $rfs->start = 1;
        }
        $rfs->flip = $this->flip;

        $rfs->xvalues = [];
        foreach ($this->ordered_symbols() as $i => $symbol) {
            $rfs->xvalues[] = $rfv = new RfValue_Setting;
            $idx = $this->flip ? $n - $i - 1 : $i;
            $rfv->id = $rfs->ids[$idx];
            $rfv->order = $i + 1;
            $rfv->symbol = $symbol;
            $rfv->name = $this->values[$idx];
            $rfv->old_value = $idx + 1;
        }
        return $rfs;
    }

    /** @return string */
    function typical_score() {
        $n = count($this->values);
        $d1 = $n > 3 ? 2 : ($n > 2 ? 1 : 0);
        $sym = $this->symbols[$this->flip ? $n - $d1 - 1 : $d1] ?? "";
        return (string) $sym;
    }

    /** @return ?array{string,string} */
    function typical_score_range() {
        $n = count($this->values);
        if ($n < 2) {
            return null;
        }
        $d0 = $n > 2 ? 1 : 0;
        $d1 = $n > 3 ? 2 : $d0;
        return [
            (string) $this->symbols[$this->flip ? $n - $d0 - 1 : $d0],
            (string) $this->symbols[$this->flip ? $n - $d1 - 2 : $d1 + 1]
        ];
    }

    /** @return ?array{string,string} */
    function full_score_range() {
        $f = $this->flip ? count($this->values) : 1;
        $l = $this->flip ? 1 : count($this->values);
        return [
            (string) $this->symbols[$f - 1],
            (string) $this->symbols[$l - 1]
        ];
    }

    /** @param int|float $fval
     * @return string */
    function value_class($fval) {
        return Discrete_ReviewField::scheme_value_class($this->scheme, $fval, count($this->values), $this->flip);
    }

    /** @param string $args
     * @return string */
    protected function annotate_graph_arguments($args) {
        if (($this->flags & self::FLAG_NUMERIC) === 0) {
            $n = count($this->values);
            $args .= "&amp;lo=" . $this->symbols[$this->flip ? $n - 1 : 0]
                . "&amp;hi=" . $this->symbols[$this->flip ? 0 : $n - 1];
        }
        if ($this->flip) {
            $args .= "&amp;flip=1";
        }
        if ($this->scheme !== "sv") {
            $args .= "&amp;sv=" . $this->scheme;
        }
        return $args;
    }

    /** @param string $s
     * @return null|0|false */
    static function check_none($s) {
        if ($s === "" || $s[0] === "(" || $s === "undefined") {
            return null;
        } else if (in_array(strtolower($s), ["none", "n/a", "0", "-", "–", "—", "no entry"])
                   || substr_compare($s, "none ", 0, 5, true) === 0) {
            return 0;
        } else {
            return false;
        }
    }
}


class Score_ReviewField extends DiscreteValues_ReviewField {
    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        parent::__construct($conf, $finfo, $j);

        if (!isset($j->required)) {
            if (isset($j->allow_empty) /* XXX backward compat */) {
                $this->required = !$j->allow_empty;
            } else {
                $this->required = true;
            }
        }
    }

    function export_json($style) {
        $j = parent::export_json($style);
        $j->required = $this->required; // want `required: false`
        return $j;
    }

    function unparse_value($fval) {
        if ($fval > 0) {
            return (string) $this->symbols[$fval - 1];
        } else {
            return "";
        }
    }

    function unparse_json($fval) {
        assert($fval === null || is_int($fval));
        if ($fval !== null) {
            return $fval > 0 ? $this->symbols[$fval - 1] : false;
        } else {
            return null;
        }
    }

    function unparse_search($fval) {
        if ($fval > 0) {
            return (string) $this->symbols[$fval - 1];
        } else {
            return "none";
        }
    }

    /** @param int|float $fval
     * @param ?string $real_format
     * @return string */
    function unparse_computed($fval, $real_format = null) {
        if ($fval === null) {
            return "";
        }
        $numeric = ($this->flags & self::FLAG_NUMERIC) !== 0;
        if ($real_format !== null && $numeric) {
            return sprintf($real_format, $fval);
        }
        if ($fval <= 0.8) {
            return "–";
        }
        if (!$numeric && $fval <= count($this->values) + 0.2) {
            $rval = (int) round($fval);
            if ($fval >= $rval + 0.25 || $fval <= $rval - 0.25) {
                $ival = (int) $fval;
                $vl = $this->symbols[$ival - 1];
                $vh = $this->symbols[$ival];
                return $this->flip ? "{$vh}~{$vl}" : "{$vl}~{$vh}";
            }
            return $this->symbols[$rval - 1];
        }
        return (string) $fval;
    }

    function unparse_span_html($fval, $format = null) {
        $s = $this->unparse_computed($fval, $format);
        if ($s !== "" && ($vc = $this->value_class($fval)) !== "") {
            $s = "<span class=\"{$vc}\">{$s}</span>";
        }
        return $s;
    }

    /** @param ScoreInfo $sci
     * @param 1|2|3 $style
     * @return string */
    function unparse_graph($sci, $style) {
        $sci = $sci->excluding(0);
        if ($sci->is_empty() && $style !== self::GRAPH_STACK_REQUIRED) {
            return "";
        }
        $n = count($this->values);

        $avgtext = $this->unparse_computed($sci->mean(), "%.2f");
        if ($sci->count() > 1 && ($stddev = $sci->stddev_s())) {
            $avgtext .= sprintf(" ± %.2f", $stddev);
        }

        $counts = $sci->counts(1, $n);
        $args = "v=" . join(",", $counts);
        if (($ms = $sci->my_score()) > 0) {
            $args .= "&amp;h={$ms}";
        }
        $args = $this->annotate_graph_arguments($args);

        if ($style !== self::GRAPH_PROPORTIONS) {
            $width = 5 * $n + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:{$width}px;height:{$height}px\" data-scorechart=\"{$args}&amp;s=1\" title=\"{$avgtext}\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"{$args}&amp;s=2\" title=\"{$avgtext}\"></div><br>";
            $step = $this->flip ? -1 : 1;
            $sep = "";
            for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
                $vc = $this->value_class($i + 1);
                $retstr .= "{$sep}<span class=\"{$vc}\">{$counts[$i]}</span>";
                $sep = " ";
            }
            $retstr .= "<br><span class=\"sc_sum\">{$avgtext}</span></div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    function parse($text) {
        if (preg_match('/\A\s*+(\z|[^.,;()](?:[^.,;()]|\.[^\s.,;()])*+)/s', $text, $m)) {
            $text = rtrim($m[1]);
        }
        $sc = $this->find_symbol($text) ?? self::check_none($text);
        if ($sc === 0 && $this->required) {
            $sc = null;
        }
        return $sc;
    }

    function parse_json($j) {
        if ($j === null || $j === 0) {
            return null;
        } else if ($j === false) {
            return $this->required ? null : 0;
        } else if (($i = array_search($j, $this->symbols, true)) !== false) {
            return $i + 1;
        } else {
            return false;
        }
    }

    /** @param ?int $fval
     * @return string */
    private function unparse_choice($fval) {
        if ($fval === 0) {
            return "none";
        } else if (($fval ?? 0) > 0 && isset($this->symbols[$fval - 1])) {
            return (string) $this->symbols[$fval - 1];
        } else {
            return "undefined";
        }
    }

    /** @param int $choiceval
     * @param ?int $fval
     * @param ?int $reqval */
    private function print_radio_choice($choiceval, $fval, $reqval) {
        $symstr = $this->unparse_choice($choiceval);
        echo '<label class="checki svline"><span class="checkc">',
            Ht::radio($this->short_id, $symstr, $choiceval === $reqval, [
                "id" => "{$this->short_id}_{$symstr}",
                "data-default-checked" => $choiceval === $fval
            ]), '</span>';
        if ($choiceval > 0) {
            $vc = $this->value_class($choiceval);
            echo '<strong class="rev_num ', $vc, '">', $symstr;
            if ($this->values[$choiceval - 1] !== "") {
                echo '.</strong> ', htmlspecialchars($this->values[$choiceval - 1]);
            } else {
                echo '</strong>';
            }
        } else {
            echo 'None of the above';
        }
        echo '</label>';
    }

    private function print_web_edit_radio($fval, $reqval, $rvalues) {
        $n = count($this->values);
        $forval = $fval ?? ($this->flip ? $n : 1);
        $this->print_web_edit_open($this->short_id, "{$this->short_id}_" . $this->unparse_choice($forval), $rvalues);
        echo '<div class="revev">';
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $this->print_radio_choice($i + 1, $fval, $reqval);
        }
        if (!$this->required) {
            $this->print_radio_choice(0, $fval, $reqval);
        }
        echo '</div></div>';
    }

    private function print_web_edit_dropdown($fval, $reqval, $rvalues) {
        $n = count($this->values);
        $this->print_web_edit_open($this->short_id, null, $rvalues);
        echo '<div class="revev">';
        $opt = [];
        if ($fval === null) {
            $opt[0] = "(Choose one)";
        }
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $sym = $this->symbols[$i];
            $val = $this->values[$i];
            $opt[$sym] = $val !== "" ? "{$sym}. {$val}" : $sym;
        }
        if (!$this->required) {
            $opt["none"] = "N/A";
        }
        echo Ht::select($this->short_id, $opt, $this->unparse_choice($reqval), [
            "data-default-value" => $this->unparse_choice($fval)
        ]);
        echo '</div></div>';
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $reqval = $reqstr === null ? $fval : $this->parse($reqstr);
        if ($this->type === "dropdown") {
            $this->print_web_edit_dropdown($fval, $reqval, $rvalues);
        } else {
            $this->print_web_edit_radio($fval, $reqval, $rvalues);
        }
    }

    function unparse_text_field(&$t, $fval, $args) {
        if ($fval <= 0 && $this->required) {
            return;
        }
        $this->unparse_text_field_header($t, $args);
        if ($fval > 0 && ($sym = $this->symbols[$fval - 1] ?? null) !== null) {
            if (($val = $this->values[$fval - 1]) !== "") {
                $t[] = prefix_word_wrap("{$sym}. ", $val, strlen($sym) + 2, null, $args["flowed"]);
            } else {
                $t[] = "{$sym}\n";
            }
        } else {
            $t[] = "N/A\n";
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $this->unparse_offline_field_header($t, $args);
        $t[] = "==-== Choices:\n";
        $n = count($this->values);
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            if ($this->values[$i] !== "") {
                $y = "==-==    {$this->symbols[$i]}. ";
                /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                $t[] = prefix_word_wrap($y, $this->values[$i], str_pad("==-==", strlen($y)));
            } else {
                $t[] = "==-==   {$this->symbols[$i]}\n";
            }
        }
        if (!$this->required) {
            $t[] = "==-==    None of the above\n==-== Enter your choice:\n";
        } else if (($this->flags & self::FLAG_ALPHA) !== 0) {
            $t[] = "==-== Enter the letter of your choice:\n";
        } else if (($this->flags & self::FLAG_NUMERIC) !== 0) {
            $t[] = "==-== Enter the number of your choice:\n";
        } else {
            $t[] = "==-== Enter the symbol of your choice:\n";
        }
        $t[] = "\n";
        if (($fval ?? 0) > 0 && $fval <= count($this->symbols)) {
            $i = $fval - 1;
            if ($this->values[$i] !== "") {
                $t[] = "{$this->symbols[$i]}. {$this->values[$i]}\n";
            } else {
                $t[] = "{$this->symbols[$i]}\n";
            }
        } else if ($this->required || ($fval ?? 0) === 0) {
            $t[] = "(Your choice here)\n";
        } else {
            $t[] = "None of the above\n";
        }
    }

    function search_examples(Contact $viewer, $context) {
        $kw = $this->search_keyword();
        $score = $this->typical_score();
        $varg = new FmtArg("value", $score);
        $ex = [new SearchExample(
            $this, "{$kw}:any",
            "<0>at least one completed review has a nonempty {title} field"
        )];
        $ex[] = new SearchExample(
            $this, "{$kw}:{value}",
            "<0>at least one completed review has {title} {value}",
            $varg
        );
        if (count($this->values) > 2
            && ($this->flags & self::FLAG_NUMERIC) !== 0) {
            $ex[] = new SearchExample(
                $this, "{$kw}:{comparison}",
                "<0>at least one completed review has {title} greater than {value}",
                $varg, new FmtArg("comparison", ">{$score}", 0)
            );
        }
        if (count($this->values) > 2
            && ($sr = $this->typical_score_range())) {
            $fmtargs = [new FmtArg("v1", $sr[0]), new FmtArg("v2", $sr[1])];
            $ex[] = new SearchExample(
                $this, "{$kw}:any:{v1}-{v2}",
                "<0>at least one completed review has {title} in the {v1}–{v2} range",
                ...$fmtargs
            );
            $ex[] = (new SearchExample(
                $this, "{$kw}:all:{v1}-{v2}",
                "<5><em>all</em> completed reviews have {title} in the {v1}–{v2} range",
                ...$fmtargs
            ))->primary_only(true);
            $ex[] = (new SearchExample(
                $this, "{$kw}:span:{v1}-{v2}",
                "<5>{title} in completed reviews <em>spans</em> the {v1}–{v2} range",
                ...$fmtargs
            ))->hint("<0>This means all scores between {v1} and {v2}, with at least one {v1} and at least one {v2}.")
              ->primary_only(true);
        }
        if (!$this->required) {
            $ex[] = new SearchExample(
                $this, "{$kw}:none",
                "<0>at least one completed review has an empty {value} field"
            );
        }

        // counts
        $ex[] = (new SearchExample(
            $this, "{$kw}:{count}:{value}",
            "<0>at least {count} completed reviews have {title} {value}",
            $varg, new FmtArg("count", 4)
        ))->primary_only(true);
        $ex[] = (new SearchExample(
            $this, "{$kw}:={count}:{value}",
            "<5><em>exactly</em> {count} completed reviews have {title} {value}",
            $varg, new FmtArg("count", 2)
        ))->primary_only(true);

        // review rounds
        if ($viewer->isPC && $this->conf->has_rounds()) {
            $dr = $this->conf->defined_rounds();
            if (count($dr) > 1) {
                $ex[] = (new SearchExample(
                    $this, "{$kw}:{round}:{value}",
                    "<0>at least one completed review in round {round} has {title} {value}",
                    $varg, new FmtArg("round", (array_values($dr))[1])
                ))->primary_only(true);
            }
        }

        // review types
        if ($viewer->isPC) {
            $ex[] = (new SearchExample(
                $this, "{$kw}:ext:{value}",
                "<0>at least one completed external review has {title} {value}",
                $varg
            ))->primary_only(true);
        }

        // reviewer
        if ($viewer->can_view_some_review_identity()) {
            $ex[] = (new SearchExample(
                $this, "{$kw}:{reviewer}:{value}",
                "<0>a reviewer whose name or email matches “{reviewer}” completed a review with {title} {value}",
                $varg, new FmtArg("reviewer", "sylvia")
            ))->primary_only(true);
        }

        return $ex;
    }

    function parse_search(SearchWord $sword, ReviewSearchMatcher $rsm, PaperSearch $srch) {
        return Discrete_ReviewFieldSearch::parse_score($sword, $this, $rsm, $srch);
    }

    function renumber_value($fmap, $fval) {
        return $fmap[$fval] ?? $fval;
    }
}


class Text_ReviewField extends ReviewField {
    /** @var int */
    public $display_space;

    function __construct(Conf $conf, ReviewFieldInfo $finfo, $j) {
        assert(!$finfo->is_sfield);
        parent::__construct($conf, $finfo, $j);

        $this->display_space = max($this->display_space ?? 0, 3);
    }

    function export_json($style) {
        $j = parent::export_json($style);
        if ($this->display_space > 3) {
            $j->display_space = $this->display_space;
        }
        return $j;
    }

    function value_present($fval) {
        return $fval !== null && $fval !== "";
    }

    function value_clean_storage($fval) {
        if ($fval !== null && $fval !== "" && !ctype_space($fval)) {
            return $fval;
        } else {
            return null;
        }
    }

    /** @return bool */
    function include_word_count() {
        return $this->order && $this->view_score >= VIEWSCORE_AUTHORDEC;
    }

    function unparse_value($fval) {
        return $fval ?? "";
    }

    function unparse_json($fval) {
        return $fval;
    }

    function parse($text) {
        $text = rtrim($text);
        if ($text === "") {
            return null;
        }
        return $text . "\n";
    }

    function parse_json($j) {
        if ($j === null) {
            return null;
        } else if (is_string($j)) {
            return $this->parse($j);
        } else {
            return false;
        }
    }

    function print_web_edit($fval, $reqstr, $rvalues, $args) {
        $this->print_web_edit_open(null, $this->short_id, $rvalues);
        echo '<div class="revev">';
        if (($fi = $args["format"])) {
            echo $fi->description_preview_html();
        }
        $opt = ["class" => "w-text need-autogrow need-suggest suggest-emoji", "rows" => $this->display_space, "cols" => 60, "spellcheck" => true, "id" => $this->short_id];
        if ($reqstr !== null && $fval !== $reqstr) {
            $opt["data-default-value"] = (string) $fval;
        }
        echo Ht::textarea($this->short_id, $reqstr ?? $fval ?? "", $opt), '</div></div>';
    }

    function unparse_text_field(&$t, $fval, $args) {
        if ($fval !== "") {
            $this->unparse_text_field_header($t, $args);
            $t[] = rtrim($fval);
            $t[] = "\n";
        }
    }

    function unparse_offline(&$t, $fval, $args) {
        $this->unparse_offline_field_header($t, $args);
        if (($fi = $args["format"])
            && ($desc = $fi->description_text()) !== "") {
            $t[] = prefix_word_wrap("==-== ", $desc, "==-== ");
        }
        $t[] = "\n";
        $t[] = preg_replace('/^(?===[-+*]==)/m', '\\', rtrim($fval ?? ""));
        $t[] = "\n";
    }

    function search_examples(Contact $viewer, $context) {
        $kw = $this->search_keyword();
        return [
            new SearchExample(
                $this, "{$kw}:any",
                "<0>at least one completed review has a nonempty {title} field"
            ),
            (new SearchExample(
                $this, "{$kw}:{text}",
                "<0>at least one completed review has “{text}” in the {title} field",
                new FmtArg("text", "finger")
            ))->primary_only(true)
        ];
    }

    function parse_search(SearchWord $sword, ReviewSearchMatcher $rsm, PaperSearch $srch) {
        list($word, $quoted) = SearchWord::maybe_unquote($sword->cword);
        $preg = Text::star_text_pregexes($word, $quoted);
        return new Text_ReviewFieldSearch($this, $rsm->rfop, $preg);
    }
}
