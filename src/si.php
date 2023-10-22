<?php
// si.php -- HotCRP conference settings information class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Si {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var string */
    public $name;
    /** @var ?list<string> */
    private $name_parts;
    /** @var ?string */
    public $name0;
    /** @var ?string */
    public $name1;
    /** @var ?string */
    public $name2;
    /** @var string */
    public $json_name;
    /** @var ?string
     * @readonly */
    public $type;
    /** @var ?string
     * @readonly */
    public $subtype;
    /** @var ?Sitype
     * @readonly */
    private $_tclass;
    /** @var string
     * @readonly */
    private $title;
    /** @var ?string
     * @readonly */
    private $title_pattern;
    /** @var ?string
     * @readonly */
    public $summary;
    /** @var ?string
     * @readonly */
    public $description;
    /** @var ?list<string> */
    public $pages;
    /** @var bool */
    private $_has_pages = false;
    /** @var null|int|float
     * @readonly */
    public $order;
    /** @var null|int|float
     * @readonly */
    public $parse_order;
    /** @var null|int
     * @readonly */
    public $__source_order;
    /** @var null|false|string
     * @readonly */
    private $hashid;
    /** @var bool
     * @readonly */
    public $internal = false;
    /** @var int
     * @readonly */
    public $storage_type;
    /** @var ?string
     * @readonly */
    private $storage;
    /** @var ?bool */
    public $required;
    /** @var null|'auto'|list
     * @readonly */
    private $values;
    /** @var null|'auto'|list
     * @readonly */
    private $json_values;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $placeholder;
    /** @var class-string
     * @readonly */
    public $parser_class;
    /** @var bool
     * @readonly */
    public $configurable = true;
    /** @var mixed
     * @readonly */
    private $default_value;
    /** @var mixed
     * @readonly */
    private $initial_value;
    /** @var ?bool
     * @readonly */
    public $autogrow;
    /** @var 0|1|2|3
     * @readonly */
    public $json;

    /** @var associative-array<string,bool> */
    static public $option_is_value = [];

    const SI_NONE = 0;
    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;
    const SI_NEGATE = 16; // requires SI_VALUE
    const SI_MEMBER = 32;

    static private $key_storage = [
        "autogrow" => "is_bool",
        "description" => "is_string",
        "internal" => "is_bool",
        "json_values" => "Si::is_auto_or_list",
        "order" => "is_number",
        "parse_order" => "is_number",
        "__source_order" => "is_int",
        "parser_class" => "is_string",
        "pages" => "is_string_list",
        "placeholder" => "is_string",
        "required" => "is_bool",
        "size" => "is_int",
        "subtype" => "is_string",
        "summary" => "is_string",
        "title" => "is_string",
        "title_pattern" => "is_string",
        "type" => "is_string",
        "values" => "Si::is_auto_or_list"
    ];

    static function is_auto_or_list($x) {
        return $x === "auto" || is_list($x);
    }

    private function store($key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey)) {
            if (call_user_func($typecheck, $j->$jkey)) {
                $this->$key = $j->$jkey;
            } else {
                trigger_error("setting {$j->name}.{$jkey} format error");
            }
        }
    }

    function __construct(Conf $conf, $j) {
        $this->conf = $conf;
        $this->name = $this->json_name = $j->name;
        if (isset($j->json_name)
            && ($j->json_name === false || is_string($j->json_name))) {
            $this->json_name = $j->json_name;
        }
        if (isset($j->name_parts)) {
            $n = count($j->name_parts);
            assert(is_string_list($j->name_parts) && $n >= 3);
            $this->name_parts = $j->name_parts;
            $this->name0 = $n === 3 ? $this->name_parts[0] : join("", array_slice($this->name_parts, 0, $n - 2));
            $this->name1 = $this->name_parts[$n - 2];
            $this->name2 = $this->name_parts[$n - 1];
        }
        foreach ((array) $j as $k => $v) {
            if (isset(self::$key_storage[$k])) {
                $this->store($k, $j, $k, self::$key_storage[$k]);
            }
        }
        if (isset($j->configurable) && is_bool($j->configurable)) {
            $this->configurable = $j->configurable;
        } else if (isset($j->disabled) && is_bool($j->disabled)) {
            $this->configurable = !$j->disabled;
        } else if (isset($j->configurable) || isset($j->disabled)) {
            trigger_error("setting {$j->name}.configurable format error");
        }
        if ($this->placeholder === "") {
            $this->placeholder = null;
        }
        if (isset($j->hashid)) {
            if (is_string($j->hashid) || $j->hashid === false) {
                $this->hashid = $j->hashid;
            } else {
                trigger_error("setting {$j->name}.hashid format error");
            }
        }
        if (isset($j->default_value)) {
            if (is_int($j->default_value) || is_string($j->default_value)) {
                $this->default_value = $j->default_value;
            } else {
                trigger_error("setting {$j->name}.default_value format error");
            }
        }
        if (isset($j->initial_value)) {
            if (is_int($j->initial_value) || is_string($j->initial_value)) {
                $this->initial_value = $j->initial_value;
            } else {
                trigger_error("setting {$j->name}.initial_value format error");
            }
        }

        if ($this->type
            && ($this->_tclass = Sitype::get($conf, $this->type, $this->subtype))) {
            $this->_tclass->initialize_si($this);
        }

        $storage_type = null;
        if (isset($j->storage)) {
            $s = "none";
            if (is_string($j->storage) && $j->storage !== "") {
                $s = $j->storage;
            } else if ($j->storage !== false) {
                trigger_error("setting {$j->name}.storage format error");
            }
            $dot = strpos($s, ".");
            if ($dot === 3 && str_starts_with($s, "opt")) {
                $storage_type = self::SI_DATA | self::SI_OPT;
            } else if ($dot === 3 && str_starts_with($s, "ova")) {
                $storage_type = self::SI_VALUE | self::SI_OPT;
                $s = "opt." . substr($s, 4);
            } else if ($dot === 3 && str_starts_with($s, "val")) {
                $storage_type = self::SI_VALUE | self::SI_SLICE;
                $s = substr($s, 4);
            } else if ($dot === 3 && str_starts_with($s, "dat")) {
                $storage_type = self::SI_DATA | self::SI_SLICE;
                $s = substr($s, 4);
            } else if ($dot === 3 && str_starts_with($s, "msg")) {
                $storage_type = self::SI_DATA;
            } else if ($dot === 6 && str_starts_with($s, "member")) {
                assert($this->name0 !== null);
                $storage_type = self::SI_MEMBER;
                $s = substr($s, 7);
            } else if ($dot === 6 && str_starts_with($s, "negval")) {
                $storage_type = self::SI_VALUE | self::SI_SLICE | self::SI_NEGATE;
                $s = substr($s, 7);
            } else if ($s === "none") {
                $storage_type = self::SI_NONE;
                $s = null;
            }
            $this->storage = $s;
        } else if ($this->name2 !== null && str_starts_with($this->name2, "/")) {
            $storage_type = self::SI_MEMBER;
        } else if ($this->type === "object") {
            $storage_type = self::SI_NONE;
        }
        if ($storage_type === null) {
            $storage_type = $this->_tclass ? $this->_tclass->storage_type() : self::SI_VALUE;
        }
        $this->storage_type = $storage_type;

        if (($this->storage_type & self::SI_OPT) !== 0) {
            $is_value = !!($this->storage_type & self::SI_VALUE);
            $oname = substr($this->storage ?? $this->name, 4);
            if (!isset(self::$option_is_value[$oname])) {
                self::$option_is_value[$oname] = $is_value;
            }
            if (self::$option_is_value[$oname] != $is_value) {
                error_log("{$oname}: conflicting option_is_value");
            }
        }

        $this->json = $this->internal ? 0 : 3;
        if (($je = $j->json ?? $j->json_export /* XXX */ ?? null) !== null) {
            if ($je === true || $je === "inout") {
                $this->json = 3;
            } else if ($je === false || $je === "none") {
                $this->json = 0;
            } else if ($je === "in") {
                $this->json = 1;
            } else if ($je === "out") {
                $this->json = 2;
            } else {
                error_log("setting {$this->name}.json format error");
            }
        }
    }

    /** @param string $s
     * @param ?SettingValues $sv
     * @return ?string */
    private function _expand_pattern($s, $sv) {
        $p0 = 0;
        $l = strlen($s);
        while ($p0 < $l && ($p1 = strpos($s, '$', $p0)) !== false) {
            $n = strspn($s, '$', $p1);
            if ($n === 1 && $p1 + 1 < $l && $s[$p1 + 1] === "{") {
                $rb = SearchSplitter::span_balanced_parens($s, $p1 + 2, "", true);
                if ($sv
                    && $rb < $l
                    && $s[$rb] === "}"
                    && ($t = $this->_expand_pattern_call(substr($s, $p1 + 2, $rb - $p1 - 2), $sv)) !== null) {
                    $s = substr($s, 0, $p1) . $t . substr($s, $rb + 1);
                    $p0 = $p1 + strlen($t);
                } else {
                    return null;
                }
            } else if ($this->name_parts !== null && $n * 2 - 1 < count($this->name_parts)) {
                $t = $this->name_parts[$n * 2 - 1];
                $s = substr($s, 0, $p1) . $t . substr($s, $p1 + $n);
                $p0 = $p1 + strlen($t);
            } else {
                $p0 = $p1 + 1;
            }
        }
        return $s;
    }

    /** @param string $call
     * @param ?SettingValues $sv
     * @return ?string */
    private function _expand_pattern_call($call, $sv) {
        $r = null;
        if (($f = $this->_expand_pattern(trim($call), $sv)) !== null) {
            if (str_starts_with($f, "uc ")) {
                $r = ucfirst(trim(substr($f, 3)));
            } else if (str_starts_with($f, "sv ")) {
                $r = $sv->vstr(trim(substr($f, 3)));
            }
        }
        return $r;
    }

    /** @return bool */
    function has_title() {
        return $this->title_pattern || $this->title !== $this->name;
    }

    /** @param ?SettingValues $sv
     * @return ?string */
    function title($sv = null) {
        $title = null;
        if ($this->title_pattern) {
            $title = $this->_expand_pattern($this->title_pattern, $sv);
        }
        $title = $title ?? $this->title ?? $this->name;
        if (($this->storage_type === self::SI_MEMBER || $this->storage_type === self::SI_NONE)
            && str_starts_with($title, "/")
            && ($psi = $this->conf->si($this->name0 . $this->name1))
            && ($ptitle = $psi->title($sv))) {
            $tsuf = substr($title, 1);
            if (strlen($tsuf) > 1
                && ctype_upper($tsuf[0])
                && ctype_lower($tsuf[1])) {
                $tsuf = lcfirst($tsuf);
            } else {
                $tsuf = ltrim($tsuf);
            }
            if ($tsuf !== "") {
                $title = "{$ptitle} {$tsuf}";
            } else {
                $title = $ptitle;
            }
        }
        return $title;
    }

    /** @param ?SettingValues $sv
     * @return ?string */
    function member_title($sv = null) {
        if ($this->title_pattern) {
            $title = $this->_expand_pattern($this->title_pattern, $sv) ?? $this->title;
        } else {
            $title = $this->title;
        }
        if ($title !== null
            && ($this->storage_type === self::SI_MEMBER || $this->storage_type === self::SI_NONE)
            && str_starts_with($title, "/")) {
            return ltrim(substr($title, 1));
        } else {
            return $title;
        }
    }

    /** @return ?string */
    function title_html(SettingValues $sv = null) {
        if (($t = $this->title($sv))) {
            return htmlspecialchars($t);
        } else {
            return null;
        }
    }

    /** @param string $subtype
     * @suppress PhanAccessReadOnlyProperty */
    function change_subtype($subtype) {
        assert(!!$this->type);
        if ($this->subtype !== $subtype) {
            $this->subtype = $subtype;
            $this->_tclass = Sitype::get($this->conf, $this->type, $this->subtype);
        }
    }

    /** @return bool */
    function is_top() {
        return empty($this->name_parts) && !$this->internal;
    }

    /** @param string ...$parts
     * @return bool */
    function name_matches(...$parts) {
        $nparts = count($parts);
        if (empty($this->name_parts)) {
            return $nparts === 1
                && ($parts[0] === "*" || $parts[0] === $this->name);
        }
        if (($nparts | 1) !== count($this->name_parts)) {
            return false;
        }
        if ($nparts % 2 === 0 && $this->name_parts[$nparts] !== "") {
            return false;
        }
        foreach ($parts as $i => $p) {
            if ($p !== "*" && $this->name_parts[$i] !== $p)
                return false;
        }
        return true;
    }

    /** @param int $n
     * @return string */
    function name_prefix($n) {
        assert($this->name_parts !== null && $n <= count($this->name_parts));
        $p = "";
        for ($i = 0; $i !== $n; ++$i) {
            $p .= $this->name_parts[$i];
        }
        return $p;
    }

    /** @return string */
    function storage_name() {
        if ($this->storage === null) {
            if ($this->storage_type === self::SI_MEMBER) {
                return substr($this->name2, 1);
            } else {
                return $this->name;
            }
        } else if ($this->name_parts === null) {
            return $this->storage;
        } else {
            return $this->_expand_pattern($this->storage, null);
        }
    }

    /** @return bool */
    function json_import() {
        return ($this->json & 1) !== 0;
    }

    /** @return bool */
    function json_export() {
        return ($this->json & 2) !== 0;
    }

    /** @return ?string */
    function json_path() {
        return $this->json !== 0 ? self::json_path_for($this->name) : null;
    }

    /** @param string $name
     * @return string */
    static function json_path_for($name) {
        $t = "";
        foreach (explode("/", $name) as $np) {
            if ($np !== "")
                $t = JsonParser::path_push($t, ctype_digit($np) ? intval($np) - 1 : $np);
        }
        return str_starts_with($t, "\$.") ? substr($t, 2) : $t;
    }

    private function _collect_pages() {
        $this->_has_pages = true;
        if ($this->pages === null && $this->name2 !== null) {
            $pn = $this->name0;
            if ($this->name2 !== "") {
                $pn .= $this->name1;
            } else if (str_ends_with($pn, "/")) {
                $pn = substr($pn, 0, -1);
            }
            if (($psi = $this->conf->si($pn))) {
                $psi->_collect_pages();
                $this->pages = $psi->pages;
            }
        }
    }

    /** @return ?string */
    function first_page() {
        if ($this->pages === null && !$this->_has_pages) {
            $this->_collect_pages();
        }
        return $this->pages[0] ?? null;
    }

    /** @param string $t
     * @return bool */
    function has_tag($t) {
        if ($this->pages === null && !$this->_has_pages) {
            $this->_collect_pages();
        }
        return in_array($t, $this->pages ?? []);
    }

    /** @return bool */
    function has_hashid() {
        return $this->hashid !== false;
    }

    /** @return ?string */
    function hashid() {
        if ($this->hashid === false) {
            return null;
        } else if ($this->hashid === null) {
            return $this->name;
        } else if ($this->name_parts === null) {
            return $this->hashid;
        } else {
            return $this->_expand_pattern($this->hashid, null);
        }
    }

    /** @return array<string,string> */
    function hoturl_param() {
        $param = ["group" => $this->first_page()];
        if ($this->hashid !== false) {
            $param["#"] = $this->hashid();
        }
        return $param;
    }

    /** @return string */
    function hoturl() {
        return $this->conf->hoturl("settings", $this->hoturl_param());
    }

    /** @param SettingValues $sv
     * @return string */
    function sv_hoturl($sv) {
        if ($this->hashid !== false && $this->has_tag($sv->canonical_page)) {
            return "#" . urlencode($this->hashid());
        } else {
            return $this->hoturl();
        }
    }

    /** @param SettingValues $sv
     * @return ?list */
    function values($sv) {
        if ($this->values === "auto"
            && $this->parser_class
            && ($v = $sv->si_parser($this)->values($this, $sv)) !== null) {
            return $v;
        } else {
            return $this->values !== "auto" ? $this->values : null;
        }
    }

    /** @param SettingValues $sv
     * @return ?list */
    function json_values($sv) {
        if ($this->json_values === "auto"
            && $this->parser_class
            && ($v = $sv->si_parser($this)->json_values($this, $sv)) !== null) {
            return $v;
        } else {
            return $this->json_values !== "auto" ? $this->json_values : null;
        }
    }

    /** @param SettingValues $sv
     * @return ?string */
    function placeholder($sv) {
        if ($this->placeholder === "auto"
            && $this->parser_class) {
            return $sv->si_parser($this)->placeholder($this, $sv);
        } else {
            return $this->placeholder;
        }
    }

    /** @param SettingValues $sv */
    function default_value($sv) {
        if ($this->default_value === "auto"
            && $this->parser_class
            && ($v = $sv->si_parser($this)->default_value($this, $sv)) !== null) {
            return $v;
        } else if (($this->storage_type & self::SI_DATA) !== 0) {
            if (str_starts_with($this->storage ?? "", "msg.")) {
                return $sv->conf->fmt()->default_translation(substr($this->storage_name(), 4)) ?? "";
            } else {
                return $this->default_value ?? "";
            }
        } else {
            return $this->default_value;
        }
    }

    /** @param SettingValues $sv */
    function initial_value($sv) {
        return $this->initial_value ?? $this->default_value($sv);
    }

    /** @param mixed $v
     * @param SettingValues $sv
     * @return bool */
    function value_nullable($v, $sv) {
        return $v === $this->default_value($sv)
            || ($v === "" && ($this->storage_type & Si::SI_DATA) !== 0)
            || ($this->_tclass && $this->_tclass->nullable($v, $this, $sv));
    }

    /** @param ?string $reqv
     * @return null|int|string */
    function parse_reqv($reqv, SettingValues $sv) {
        if ($reqv === null) {
            return $this->_tclass ? $this->_tclass->parse_null_vstr($this) : null;
        } else if ($this->_tclass) {
            $v = trim($reqv);
            if ($v === $this->placeholder($sv)) {
                $v = "";
            }
            return $this->_tclass->parse_reqv($v, $this, $sv);
        } else {
            throw new ErrorException("Don't know how to parse_reqv {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return string */
    function base_unparse_reqv($v, SettingValues $sv) {
        if ($this->_tclass) {
            return $this->_tclass->unparse_reqv($v, $this, $sv);
        } else {
            return (string) $v;
        }
    }

    /** @param mixed $jv
     * @return ?string */
    function jsonv_reqstr($jv, SettingValues $sv) {
        if ($this->_tclass) {
            if (is_string($jv)) {
                $jv = trim($jv);
                if ($jv === $this->placeholder($sv)) {
                    $jv = "";
                }
            }
            return $this->_tclass->jsonv_reqstr($jv, $this, $sv);
        } else {
            throw new ErrorException("Don't know how to jsonv_reqstr {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return mixed */
    function base_unparse_jsonv($v, SettingValues $sv) {
        if ($this->_tclass) {
            return $this->_tclass->unparse_jsonv($v, $this, $sv);
        } else {
            return $v;
        }
    }

    /** @return mixed */
    function json_examples(SettingValues $sv) {
        return $this->_tclass ? $this->_tclass->json_examples($this, $sv) : null;
    }

    /** @param Si $xta
     * @param Si $xtb
     * @return -1|0|1 */
    static function parse_order_compare($xta, $xtb) {
        $ap = $xta->parse_order ?? $xta->order ?? 0;
        $ap = $ap !== false ? $ap : INF;
        $bp = $xtb->parse_order ?? $xtb->order ?? 0;
        $bp = $bp !== false ? $bp : INF;
        if ($ap == $bp) {
            $ap = $xta->__source_order ?? 0;
            $bp = $xtb->__source_order ?? 0;
        }
        return $ap <=> $bp;
    }
}
