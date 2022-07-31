<?php
// reviewfield.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    public $has_options;
    /** @var ?non-empty-string
     * @readonly */
    public $main_storage;
    /** @var ?non-empty-string
     * @readonly */
    public $json_storage;

    // see also Signature properties in PaperInfo
    /** @var list<?non-empty-string>
     * @readonly */
    static private $new_score_fields = [
        "overAllMerit", "reviewerQualification", "novelty", "technicalMerit",
        "interestToCommunity", "longevity", "grammar", "likelyPresentation",
        "suitableForShort", "potential", "fixability"
    ];
    /** @var array<string,ReviewFieldInfo> */
    static private $field_info_map = [];

    /** @param bool $has_options
     * @param ?non-empty-string $main_storage
     * @param ?non-empty-string $json_storage
     * @phan-assert non-empty-string $short_id */
    function __construct($short_id, $has_options, $main_storage, $json_storage) {
        $this->short_id = $short_id;
        $this->has_options = $has_options;
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
                && ($n = array_search($id, self::$new_score_fields)) !== false) {
                $id = sprintf("s%02d", $n + 1);
            }
            if (strlen($id) === 3
                && ($id[0] === "s" || $id[0] === "t")
                && ($d1 = ord($id[1])) >= 48
                && $d1 <= 57
                && ($d2 = ord($id[2])) >= 48
                && $d2 <= 57
                && ($n = ($d1 - 48) * 10 + $d2 - 48) > 0) {
                if ($id[0] === "s" && $n < 12) {
                    $storage = $sv >= 260 ? $id : self::$new_score_fields[$n - 1];
                    $m = new ReviewFieldInfo($id, true, $storage, null);
                    self::$field_info_map[self::$new_score_fields[$n - 1]] = $m;
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
    const VALUE_NONE = 0;
    const VALUE_SC = 1;
    const VALUE_NATIVE = 2;
    const VALUE_TRIM = 4;

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
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $_search_keyword;
    /** @var bool */
    public $has_options;
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
    /** @var ?non-empty-string */
    public $main_storage;
    /** @var ?non-empty-string */
    public $json_storage;

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

    // colors
    /** @var array<string,list>
     * @readonly */
    static public $scheme_info = [
        "sv" => [0, 9, "svr"], "svr" => [1, 9, "sv"],
        "blpu" => [0, 9, "publ"], "publ" => [1, 9, "blpu"],
        "rdpk" => [1, 9, "pkrd"], "pkrd" => [0, 9, "rdpk"],
        "viridisr" => [1, 9, "viridis"], "viridis" => [0, 9, "viridisr"],
        "orbu" => [0, 9, "buor"], "buor" => [1, 9, "orbu"],
        "turbo" => [0, 9, "turbor"], "turbor" => [1, 9, "turbo"],
        "catx" => [2, 10, null], "none" => [2, 1, null]
    ];

    function __construct(Conf $conf, ReviewFieldInfo $finfo) {
        $this->short_id = $finfo->short_id;
        $this->has_options = $finfo->has_options;
        $this->main_storage = $finfo->main_storage;
        $this->json_storage = $finfo->json_storage;
        $this->conf = $conf;
    }

    /** @param ReviewFieldInfo $rfi
     * @return ReviewField */
    static function make(Conf $conf, $rfi) {
        if ($rfi->has_options) {
            return new Score_ReviewField($conf, $rfi);
        } else {
            return new Text_ReviewField($conf, $rfi);
        }
    }

    /** @param bool $has_options
     * @return ReviewField */
    static function make_template(Conf $conf, $has_options) {
        $id = $has_options ? "s00" : "t00";
        return self::make($conf, new ReviewFieldInfo($id, $has_options, null, null));
    }

    /** @param object $j */
    function assign_json($j) {
        $this->name = $j->name ?? "Field name";
        $this->name_html = htmlspecialchars($this->name);
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
               && preg_match('/\A\((?:(?:hidden|invisible|visible|shown)(?:| (?:from|to|from the|to the) authors?)|pc only|shown only to chairs|secret|private)(?:| until decision| and external reviewers)[.?!]?\)\z/', substr($s, $lparen))) {
            $s = rtrim(substr($s, 0, $lparen));
        }
        return $s;
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
    function unparse_json($style) {
        $j = (object) [];
        if ($style > 0) {
            $j->id = $this->short_id;
        } else {
            $j->uid = $this->uid();
        }
        $j->name = $this->name;
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
        if ($this->exists_if) {
            $j->exists_if = $this->exists_if;
        } else if ($this->round_mask) {
            if ($style === self::UJ_STORAGE) {
                $j->round_mask = $this->round_mask;
            } else if ($this->round_mask) {
                $j->exists_if = $this->unparse_round_mask();
            }
        }
        return $j;
    }

    /** @param Rf_Setting $rfs */
    function unparse_setting($rfs) {
        $rfs->id = $this->short_id;
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
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->unparse_json(self::UJ_EXPORT);
    }

    /** @return string */
    function unparse_visibility() {
        return self::$view_score_rmap[$this->view_score] ?? (string) $this->view_score;
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

    /** @return bool */
    function test_exists(ReviewInfo $rrow) {
        if ($this->_need_exists_search) {
            $search = new PaperSearch($this->conf->root_user(), $this->exists_if);
            $this->_exists_search = $search->term();
            $this->_need_exists_search = false;
        }
        return (!$this->round_mask || ($this->round_mask & (1 << $rrow->reviewRound)) !== 0)
            && (!$this->_exists_search || $this->_exists_search->test($rrow->prow, $rrow));
    }

    /** @param ?int|string $value
     * @return bool */
    abstract function value_empty($value);

    /** @return bool */
    function include_word_count() {
        return false;
    }

    /** @return ?string */
    function typical_score() {
        return null;
    }

    /** @return ?array{string,string} */
    function typical_score_range() {
        return null;
    }

    /** @return ?array{string,string} */
    function full_score_range() {
        return null;
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
        return '<span class="need-tooltip" data-tooltip="' . $this->name_html
            . '" data-tooltip-anchor="s">' . htmlspecialchars($this->search_keyword()) . "</span>";
    }

    /** @return string */
    function uid() {
        return $this->search_keyword();
    }

    /** @param int|float|string $value
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    abstract function unparse_value($value, $flags = 0, $real_format = null);

    /** @param string $text
     * @return int|string|false */
    abstract function parse_value($text);

    /** @param string $text
     * @return bool */
    function parse_is_explicit_empty($text) {
        return false;
    }

    /** @param int|string $fval
     * @return int|string */
    function normalize_value($fval) {
        return $fval;
    }

    /** @param ?string $id
     * @param string $label_for
     * @param ?ReviewValues $rvalues
     * @param ?array{name_html?:string,label_class?:string} $args */
    protected function print_web_edit_open($id, $label_for, $rvalues, $args = null) {
        echo '<div class="rf rfe" data-rf="', $this->uid(),
            '"><h3 class="',
            $rvalues ? $rvalues->control_class($this->short_id, "rfehead") : "rfehead";
        if ($id !== null) {
            echo '" id="', $id;
        }
        echo '"><label class="', $args["label_class"] ?? "revfn";
        if ($this->required) {
            echo ' field-required';
        }
        echo '" for="', $label_for, '">', $args["name_html"] ?? $this->name_html, '</label>';
        if ($this->view_score < VIEWSCORE_AUTHOR) {
            echo '<div class="field-visibility">';
            if ($this->view_score < VIEWSCORE_REVIEWERONLY) {
                echo '(secret)';
            } else if ($this->view_score < VIEWSCORE_PC) {
                echo '(shown only to chairs)';
            } else if ($this->view_score < VIEWSCORE_REVIEWER) {
                echo '(hidden from authors and external reviewers)';
            } else if ($this->view_score < VIEWSCORE_AUTHORDEC) {
                echo '(hidden from authors)';
            } else {
                echo '(hidden from authors until decision)';
            }
            echo '</div>';
        }
        echo '</h3>';
        if ($rvalues) {
            echo $rvalues->feedback_html_at($this->short_id);
        }
        if ($this->description) {
            echo '<div class="field-d">', $this->description, "</div>";
        }
    }

    /** @param int|string $fv
     * @param int|string $reqv
     * @param array{format:?TextFormat,rvalues:?ReviewValues} $args */
    abstract function print_web_edit($fv, $reqv, $args);

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
     * @param string $fv
     * @param array{flowed:bool} $args */
    abstract function unparse_text_field(&$t, $fv, $args);

    /** @param string $fv
     * @return string */
    function unparse_text_field_content($fv) {
        $t = [];
        $this->unparse_text_field($t, $fv, ["flowed" => false]);
        return join("", $t);
    }

    /** @param list<string> &$t */
    protected function unparse_offline_field_header(&$t, $args) {
        $t[] = prefix_word_wrap("==*== ", $this->name, "==*==    ");
        if ($this->view_score < VIEWSCORE_REVIEWERONLY) {
            $t[] = "==-== (secret field)\n";
        } else if ($this->view_score < VIEWSCORE_PC) {
            $t[] = "==-== (shown only to chairs)\n";
        } else if ($this->view_score < VIEWSCORE_REVIEWER) {
            $t[] = "==-== (hidden from authors and external reviewers)\n";
        } else if ($this->view_score < VIEWSCORE_AUTHORDEC) {
            $t[] = "==-== (hidden from authors)\n";
        } else if ($this->view_score < VIEWSCORE_AUTHOR) {
            $t[] = "==-== (hidden from authors until decision)\n";
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
     * @param string $fv
     * @param array{format:?TextFormat,include_presence:bool} $args */
    abstract function unparse_offline_field(&$t, $fv, $args);
}


class Score_ReviewField extends ReviewField {
    /** @var list<string> */
    private $values = [];
    /** @var list<int|string> */
    private $symbols = [];
    /** @var ?list<int> */
    private $ids;
    /** @var int */
    public $option_letter = 0;
    /** @var bool */
    public $flip = false;
    /** @var string */
    public $scheme = "sv";
    /** @var ?string */
    private $_typical_score;

    // colors
    /** @var array<string,list> */
    static public $scheme_info = [
        "sv" => [0, 9, "svr"], "svr" => [1, 9, "sv"],
        "blpu" => [0, 9, "publ"], "publ" => [1, 9, "blpu"],
        "rdpk" => [1, 9, "pkrd"], "pkrd" => [0, 9, "rdpk"],
        "viridisr" => [1, 9, "viridis"], "viridis" => [0, 9, "viridisr"],
        "orbu" => [0, 9, "buor"], "buor" => [1, 9, "orbu"],
        "turbo" => [0, 9, "turbor"], "turbor" => [1, 9, "turbo"],
        "catx" => [2, 10, null], "none" => [2, 1, null]
    ];

    function __construct(Conf $conf, ReviewFieldInfo $finfo) {
        assert($finfo->has_options);
        parent::__construct($conf, $finfo);
        $this->has_options = true;
    }

    /** @param object $j */
    function assign_json($j) {
        parent::assign_json($j);
        $this->values = $j->values ?? $j->options ?? [];
        $nvalues = count($this->values);
        $ol = $j->start ?? $j->option_letter ?? null;
        $this->option_letter = 0;
        $this->symbols = [];
        $this->flip = false;
        if (isset($j->symbols) && count($j->symbols) === $nvalues) {
            $this->symbols = $j->symbols;
        } else if ($ol && is_string($ol) && ctype_upper($ol) && strlen($ol) === 1) {
            $this->option_letter = ord($ol) + $nvalues;
            $this->flip = true;
            for ($i = 0; $i !== $nvalues; ++$i) {
                $this->symbols[] = chr($this->option_letter - $i - 1);
            }
        } else {
            for ($i = 0; $i !== $nvalues; ++$i) {
                $this->symbols[] = $i + 1;
            }
        }
        if (isset($j->ids) && count($j->ids) === $nvalues) {
            $this->ids = $j->ids;
        }
        if (isset($j->scheme)) {
            $this->scheme = $j->scheme;
        } else if (isset($j->option_class_prefix) /* XXX backward compat */) {
            $p = $j->option_class_prefix;
            if (str_starts_with($p, "sv-")) {
                $p = substr($p, 3);
            }
            if ($this->option_letter && isset(self::$scheme_info[$p])) {
                $p = self::$scheme_info[$p][2] ?? $p;
            }
            $this->scheme = $p;
        }
        if (!isset($j->required)) {
            if (isset($j->allow_empty) /* XXX backward compat */) {
                $this->required = !$j->allow_empty;
            } else {
                $this->required = true;
            }
        }
        $this->_typical_score = null;
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

    function unparse_json($style) {
        $j = parent::unparse_json($style);
        $j->values = $this->values;
        if ($this->ids
            && ($style !== self::UJ_STORAGE
                || $this->ids !== range(1, count($this->values)))) {
            $j->ids = $this->ids;
        }
        if ($this->option_letter) {
            $j->start = chr($this->option_letter - count($this->values));
        }
        if ($this->flip) {
            $j->flip = true;
        }
        if ($this->scheme !== "sv") {
            $j->scheme = $this->scheme;
        }
        $j->required = $this->required;
        return $j;
    }

    function unparse_setting($rfs) {
        parent::unparse_setting($rfs);
        $rfs->type = "radio";
        $n = count($this->values);
        $rfs->values = $this->values;
        if ($this->option_letter) {
            $rfs->start = chr($this->option_letter - $n);
        } else {
            $rfs->start = 1;
        }
        $rfs->flip = $this->flip;
        $rfs->scheme = $this->scheme;

        $rfs->xvalues = [];
        foreach ($this->ordered_symbols() as $i => $symbol) {
            $rfs->xvalues[] = $rfv = new RfValue_Setting;
            $idx = $this->flip ? $n - $i - 1 : $i;
            $rfv->id = $this->ids ? $this->ids[$idx] : $idx + 1;
            $rfv->order = $i + 1;
            $rfv->symbol = $symbol;
            $rfv->name = $this->values[$idx];
            $rfv->old_index = $idx;
        }
    }

    /** @return int */
    function nvalues() {
        return count($this->values);
    }

    /** @param ?int|string $value
     * @return bool */
    function value_empty($value) {
        // see also ReviewInfo::has_nonempty_field
        return $value === null
            || $value === ""
            || (int) $value === 0;
    }

    /** @return ?string */
    function typical_score() {
        if ($this->_typical_score === null) {
            $n = count($this->values);
            if ($n === 1) {
                $this->_typical_score = $this->unparse_value(1);
            } else if ($this->option_letter) {
                $this->_typical_score = $this->unparse_value(1 + (int) (($n - 1) / 2));
            } else {
                $this->_typical_score = $this->unparse_value(2);
            }
        }
        return $this->_typical_score;
    }

    /** @return ?array{string,string} */
    function typical_score_range() {
        $n = count($this->values);
        if ($n < 2) {
            return null;
        } else if ($this->option_letter) {
            return [$this->unparse_value($n - ($n > 2 ? 1 : 0)), $this->unparse_value($n - 1 - ($n > 2 ? 1 : 0) - ($n > 3 ? 1 : 0))];
        } else {
            return [$this->unparse_value(1 + ($n > 2 ? 1 : 0)), $this->unparse_value(2 + ($n > 2 ? 1 : 0) + ($n > 3 ? 1 : 0))];
        }
    }

    /** @return ?array{string,string} */
    function full_score_range() {
        $f = $this->flip ? count($this->values) : 1;
        $l = $this->flip ? 1 : count($this->values);
        return [$this->unparse_value($f), $this->unparse_value($l)];
    }

    /** @param int $option_letter
     * @param int|float $value
     * @return string */
    static function unparse_letter($option_letter, $value) {
        $ivalue = (int) $value;
        $ch = $option_letter - $ivalue;
        if ($value < $ivalue + 0.25) {
            return chr($ch);
        } else if ($value < $ivalue + 0.75) {
            return chr($ch - 1) . chr($ch);
        } else {
            return chr($ch - 1);
        }
    }

    /** @param int|float $value
     * @return string */
    function value_class($value) {
        $info = self::$scheme_info[$this->scheme];
        if (count($this->values) <= 1) {
            $n = $info[1] - 1;
        } else if ($info[0] & 2) {
            $n = (int) round($value - 1) % $info[1];
        } else {
            $n = (int) round(($value - 1) * ($info[1] - 1) / (count($this->values) - 1));
        }
        $sclass = $info[0] & 1 ? $info[2] : $this->scheme;
        if ((($info[0] & 1) !== 0) !== $this->flip) {
            $n = $info[1] - $n;
        } else {
            $n += 1;
        }
        if ($sclass === "sv") {
            return "sv sv{$n}";
        } else {
            return "sv sv-{$sclass}{$n}";
        }
    }

    /** @param int|float|string $value
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    function unparse_value($value, $flags = 0, $real_format = null) {
        if (!$value) {
            return null;
        }
        if (!$this->option_letter || is_numeric($value)) {
            $value = (float) $value;
        } else if (strlen($value) === 1) {
            $value = (float) $this->option_letter - ord($value);
        } else if (ord($value[0]) + 1 === ord($value[1])) {
            $value = ($this->option_letter - ord($value[0])) - 0.5;
        }
        if (!is_float($value) || $value <= 0.8) {
            return null;
        }
        if ($this->option_letter) {
            $text = self::unparse_letter($this->option_letter, $value);
        } else if ($real_format) {
            $text = sprintf($real_format, $value);
        } else if (($flags & self::VALUE_NATIVE) === 0) {
            $text = (string) $value;
        } else {
            $text = $value;
        }
        if (($flags & self::VALUE_SC) !== 0) {
            $vc = $this->value_class($value);
            $text = "<span class=\"{$vc}\">{$text}</span>";
        }
        return $text;
    }

    /** @param int|float $value */
    function unparse_average($value) {
        return (string) $this->unparse_value($value, 0, "%.2f");
    }

    /** @param ScoreInfo $sci
     * @param 1|2 $style
     * @return string */
    function unparse_graph($sci, $style) {
        $max = count($this->values);

        $avgtext = $this->unparse_average($sci->mean());
        if ($sci->count() > 1 && ($stddev = $sci->stddev_s())) {
            $avgtext .= sprintf(" ± %.2f", $stddev);
        }

        $counts = $sci->counts($max);
        $args = "v=" . join(",", $counts);
        if ($sci->my_score() && $counts[$sci->my_score() - 1] > 0) {
            $args .= "&amp;h=" . $sci->my_score();
        }
        if ($this->option_letter) {
            $args .= "&amp;c=" . chr($this->option_letter - 1);
        }
        if ($this->scheme !== "sv") {
            $args .= "&amp;sv=" . urlencode($this->scheme);
        }

        if ($style == 1) {
            $width = 5 * $max + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:{$width}px;height:{$height}px\" data-scorechart=\"{$args}&amp;s=1\" title=\"{$avgtext}\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"{$args}&amp;s=2\" title=\"{$avgtext}\"></div><br>";
            if ($this->flip) {
                for ($key = $max; $key >= 1; --$key) {
                    $retstr .= ($key < $max ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
                }
            } else {
                for ($key = 1; $key <= $max; ++$key) {
                    $retstr .= ($key > 1 ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
                }
            }
            $retstr .= "<br><span class=\"sc_sum\">{$avgtext}</span></div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    /** @param string $text
     * @return int|false */
    function parse_value($text) {
        $text = trim($text);
        if ($text === "") {
            return 0;
        }
        if (!ctype_alnum($text)) {
            if (preg_match('/\A([A-Z]|[0-9]+)(?:[\s\.]|\z)/', $text, $m)) {
                $text = $m[1];
            } else if ($text[0] === "(" || strcasecmp($text, "No entry") === 0) {
                return 0;
            } else {
                return false;
            }
        }
        if (($i = array_search($text, $this->symbols)) !== false) {
            return $i + 1;
        } else if ($text === "0") {
            return 0;
        } else {
            return false;
        }
    }

    /** @param string $text
     * @return bool */
    function parse_is_explicit_empty($text) {
        return $text === "0" || strcasecmp($text, "No entry") === 0;
    }

    /** @param int|string $fval
     * @return int|string */
    function normalize_value($fval) {
        if (($i = array_search($fval, $this->symbols)) !== false) {
            return $this->symbols[$i];
        } else {
            return 0;
        }
    }

    /** @param int $i
     * @param int|string $fv
     * @param int|string $reqv */
    private function print_choice($i, $fv, $reqv) {
        $symbol = $i < 0 ? "0" : $this->symbols[$i];
        $opt = ["id" => "{$this->short_id}_{$symbol}"];
        if ($fv !== $reqv) {
            $opt["data-default-checked"] = $fv === $symbol;
        }
        echo '<label class="checki', ($i >= 0 ? "" : " g"), '"><span class="checkc">',
            Ht::radio($this->short_id, $symbol, $reqv === $symbol, $opt), '</span>';
        if ($i >= 0) {
            $vc = $this->value_class($i + 1);
            echo '<strong class="rev_num ', $vc, '">', $symbol;
            if ($this->values[$i] !== "") {
                echo '.</strong> ', htmlspecialchars($this->values[$i]);
            } else {
                echo '</strong>';
            }
        } else {
            echo 'No entry';
        }
        echo '</label>';
    }

    function print_web_edit($fv, $reqv, $args) {
        $n = count($this->values);
        if ($reqv || !$this->required) {
            $for = "{$this->short_id}_{$reqv}";
        } else {
            $for = "{$this->short_id}_" . $this->symbols[$this->flip ? $n - 1 : 0];
        }
        $this->print_web_edit_open($this->short_id, $for, $args["rvalues"]);
        echo '<div class="revev">';
        $step = $this->flip ? -1 : 1;
        for ($i = $this->flip ? $n - 1 : 0; $i >= 0 && $i < $n; $i += $step) {
            $this->print_choice($i, $fv, $reqv);
        }
        if (!$this->required) {
            $this->print_choice(-1, $fv, $reqv);
        }
        echo '</div></div>';
    }

    function unparse_text_field(&$t, $fv, $args) {
        if ($fv !== "" && $fv !== "0") {
            $this->unparse_text_field_header($t, $args);
            $i = array_search($fv, $this->symbols);
            if ($i !== false && $this->values[$i] !== "") {
                $t[] = prefix_word_wrap("{$fv}. ", $this->values[$i], strlen($fv) + 2, null, $args["flowed"]);
            } else {
                $t[] = "{$fv}\n";
            }
        }
    }

    function unparse_offline_field(&$t, $fv, $args) {
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
            $t[] = "==-==    No entry\n==-== Enter your choice:\n";
        } else if ($this->option_letter) {
            $t[] = "==-== Enter the letter of your choice:\n";
        } else {
            $t[] = "==-== Enter the number of your choice:\n";
        }
        $t[] = "\n";
        if (($i = array_search($fv, $this->symbols)) !== false) {
            if ($this->values[$i] !== "") {
                $t[] = "{$this->symbols[$i]}. {$this->values[$i]}\n";
            } else {
                $t[] = "{$this->symbols[$i]}\n";
            }
        } else if ($this->required) {
            $t[] = "(Your choice here)\n";
        } else {
            $t[] = "No entry\n";
        }
    }
}

class Text_ReviewField extends ReviewField {
    /** @var int */
    public $display_space;

    function __construct(Conf $conf, ReviewFieldInfo $finfo) {
        parent::__construct($conf, $finfo);
        $this->has_options = false;
    }

    /** @param object $j */
    function assign_json($j) {
        parent::assign_json($j);
        $this->display_space = max($this->display_space ?? 0, 3);
    }

    function unparse_json($style) {
        $j = parent::unparse_json($style);
        if ($this->display_space > 3) {
            $j->display_space = $this->display_space;
        }
        return $j;
    }

    function unparse_setting($rfs) {
        parent::unparse_setting($rfs);
        $rfs->type = "text";
    }

    /** @param ?int|string $value
     * @return bool */
    function value_empty($value) {
        // see also ReviewInfo::has_nonempty_field
        return $value === null || $value === "";
    }

    /** @return bool */
    function include_word_count() {
        return $this->order && $this->view_score >= VIEWSCORE_AUTHORDEC;
    }

    /** @param int|float|string $value
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    function unparse_value($value, $flags = 0, $real_format = null) {
        assert(!is_object($value));
        if ($flags & self::VALUE_TRIM) {
            $value = rtrim($value ?? "");
        }
        return $value;
    }

    /** @param string $text
     * @return int|string|false */
    function parse_value($text) {
        $text = rtrim($text);
        if ($text !== "") {
            $text .= "\n";
        }
        return $text;
    }

    function print_web_edit($fv, $reqv, $args) {
        $this->print_web_edit_open(null, $this->short_id, $args["rvalues"]);
        echo '<div class="revev">';
        if (($fi = $args["format"])) {
            echo $fi->description_preview_html();
        }
        $opt = ["class" => "w-text need-autogrow need-suggest suggest-emoji", "rows" => $this->display_space, "cols" => 60, "spellcheck" => true, "id" => $this->short_id];
        if ($fv !== $reqv) {
            $opt["data-default-value"] = (string) $fv;
        }
        echo Ht::textarea($this->short_id, (string) $reqv, $opt), '</div></div>';
    }

    function unparse_text_field(&$t, $fv, $args) {
        if ($fv !== "") {
            $this->unparse_text_field_header($t, $args);
            $t[] = $fv;
            $t[] = "\n";
        }
    }

    function unparse_offline_field(&$t, $fv, $args) {
        $this->unparse_offline_field_header($t, $args);
        if (($fi = $args["format"])
            && ($desc = $fi->description_text()) !== "") {
            $t[] = prefix_word_wrap("==-== ", $desc, "==-== ");
        }
        $t[] = "\n";
        $t[] = preg_replace('/^(?===[-+*]==)/m', '\\', $fv ?? "");
        $t[] = "\n";
    }
}
