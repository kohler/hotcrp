<?php
// si.php -- HotCRP conference settings information class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Si {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var string */
    public $name;
    /** @var ?string */
    public $part0;
    /** @var ?string */
    public $part1;
    /** @var ?string */
    public $part2;
    /** @var list<string> */
    public $parts = [];
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
    /** @var string */
    private $title;
    /** @var ?string */
    public $title_pattern;
    /** @var ?list<string> */
    public $pages;
    /** @var bool */
    private $_has_pages = false;
    /** @var null|int|float */
    public $order;
    /** @var null|int|float */
    public $parse_order;
    /** @var null|int */
    public $__source_order;
    /** @var null|false|string */
    public $hashid;
    /** @var bool */
    public $internal = false;
    /** @var int */
    public $storage_type;
    /** @var ?string */
    private $storage;
    /** @var ?bool */
    public $required;
    /** @var list */
    public $values;
    /** @var list */
    public $json_values;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $placeholder;
    /** @var class-string */
    public $parser_class;
    /** @var bool */
    public $disabled = false;
    public $default_value;
    /** @var ?bool */
    public $autogrow;
    /** @var ?string */
    public $ifnonempty;
    /** @var null|string|list<string> */
    public $default_message;

    /** @var associative-array<string,bool> */
    static public $option_is_value = [];

    const SI_NONE = 0;
    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;
    const SI_NEGATE = 16;
    const SI_MEMBER = 32;

    static private $key_storage = [
        "autogrow" => "is_bool",
        "disabled" => "is_bool",
        "ifnonempty" => "is_string",
        "internal" => "is_bool",
        "json_values" => "is_array",
        "order" => "is_number",
        "parse_order" => "is_number",
        "__source_order" => "is_int",
        "parser_class" => "is_string",
        "pages" => "is_string_list",
        "placeholder" => "is_string",
        "required" => "is_bool",
        "size" => "is_int",
        "subtype" => "is_string",
        "title" => "is_string",
        "title_pattern" => "is_string",
        "type" => "is_string",
        "values" => "is_array"
    ];

    private function store($key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey)) {
            if (call_user_func($typecheck, $j->$jkey)) {
                $this->$key = $j->$jkey;
            } else {
                trigger_error("setting {$j->name}.$jkey format error");
            }
        }
    }

    function __construct(Conf $conf, $j) {
        $this->conf = $conf;
        $this->name = $this->json_name = $this->title = $j->name;
        if (isset($j->json_name)
            && ($j->json_name === false || is_string($j->json_name))) {
            $this->json_name = $j->json_name;
        }
        if (isset($j->parts)) {
            $n = count($j->parts);
            assert(is_string_list($j->parts) && $n >= 3);
            $this->parts = $j->parts;
            $this->part0 = $n === 3 ? $j->parts[0] : join("", array_slice($j->parts, 0, $n - 2));
            $this->part1 = $j->parts[$n - 2];
            $this->part2 = $j->parts[$n - 1];
        }
        foreach ((array) $j as $k => $v) {
            if (isset(self::$key_storage[$k])) {
                $this->store($k, $j, $k, self::$key_storage[$k]);
            }
        }
        $this->default_message = $j->default_message ?? null;
        if ($this->placeholder === "") {
            $this->placeholder = null;
        }
        if (isset($j->storage)) {
            if (is_string($j->storage) && $j->storage !== "") {
                $this->storage = $j->storage;
            } else if ($j->storage === false) {
                $this->storage = "none";
            } else {
                trigger_error("setting {$j->name}.storage format error");
            }
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

        if ($this->type) {
            $this->_tclass = Sitype::get($conf, $this->type, $this->subtype);
        }
        if ($this->_tclass) {
            $this->_tclass->initialize_si($this);
        }

        $s = $this->storage ?? $this->name;
        $dot = strpos($s, ".");
        if ($dot === 3 && str_starts_with($s, "opt")) {
            $this->storage_type = self::SI_DATA | self::SI_OPT;
        } else if ($dot === 3 && str_starts_with($s, "ova")) {
            $this->storage_type = self::SI_VALUE | self::SI_OPT;
            $this->storage = "opt." . substr($s, 4);
        } else if ($dot === 3 && str_starts_with($s, "val")) {
            $this->storage_type = self::SI_VALUE | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if ($dot === 3 && str_starts_with($s, "dat")) {
            $this->storage_type = self::SI_DATA | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if ($dot === 3 && str_starts_with($s, "msg")) {
            $this->storage_type = self::SI_DATA;
            $this->default_message = $this->default_message ?? substr($s, 4);
        } else if ($dot === 6 && str_starts_with($s, "member")) {
            assert($this->part0 !== null);
            $this->storage_type = self::SI_MEMBER;
            $this->storage = substr($s, 7);
        } else if ($dot === 6 && str_starts_with($s, "negval")) {
            $this->storage_type = self::SI_VALUE | self::SI_SLICE | self::SI_NEGATE;
            $this->storage = substr($s, 7);
        } else if ($this->storage === "none") {
            $this->storage_type = self::SI_NONE;
        } else if ($this->_tclass) {
            $this->storage_type = $this->_tclass->storage_type();
        } else {
            $this->storage_type = self::SI_VALUE;
        }
        if ($this->storage_type & self::SI_OPT) {
            $is_value = !!($this->storage_type & self::SI_VALUE);
            $oname = substr($this->storage ?? $this->name, 4);
            if (!isset(self::$option_is_value[$oname])) {
                self::$option_is_value[$oname] = $is_value;
            }
            if (self::$option_is_value[$oname] != $is_value) {
                error_log("$oname: conflicting option_is_value");
            }
        }

        // resolve extension
        if ($this->parts !== null && is_string($this->storage)) {
            $this->storage = $this->_expand_pattern($this->storage, null);
        }
        if ($this->parts !== null && is_string($this->hashid)) {
            $this->hashid = $this->_expand_pattern($this->hashid, null);
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
                $rb = SearchSplitter::span_balanced_parens($s, $p1 + 2, "");
                if ($sv
                    && $rb < $l
                    && $s[$rb] === "}"
                    && ($t = $this->_expand_pattern_call(substr($s, $p1 + 2, $rb - $p1 - 2), $sv)) !== null) {
                    $s = substr($s, 0, $p1) . $t . substr($s, $rb + 1);
                    $p0 = $p1 + strlen($t);
                } else {
                    return null;
                }
            } else if ($this->parts !== null && $n * 2 - 1 < count($this->parts)) {
                $t = $this->parts[$n * 2 - 1];
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

    /** @param ?SettingValues $sv
     * @return ?string */
    function title($sv = null) {
        if ($this->title_pattern
            && ($title = $this->_expand_pattern($this->title_pattern, $sv)) !== null) {
            return $title;
        } else {
            return $this->title;
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

    /** @return string */
    function storage_name() {
        return $this->storage ?? $this->name;
    }

    private function _collect_pages() {
        $this->_has_pages = true;
        if ($this->pages === null
            && $this->part2 !== null
            && $this->part2 !== ""
            && ($psi = $this->conf->si($this->part0 . $this->part1))) {
            $psi->_collect_pages();
            $this->pages = $psi->pages;
        }
        if ($this->pages === null) {
            error_log("no pages for {$this->name}\n" . debug_string_backtrace());
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
        return $this->pages === null || in_array($t, $this->pages);
    }

    /** @return array<string,string> */
    function hoturl_param() {
        $param = ["group" => $this->first_page()];
        if ($this->hashid !== false) {
            $param["#"] = $this->hashid ?? $this->name;
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
            return "#" . urlencode($this->hashid ?? $this->name);
        } else {
            return $this->hoturl();
        }
    }

    /** @param SettingValues $sv */
    function default_value($sv) {
        if ($this->default_message) {
            $dm = $this->default_message;
            $id = is_string($dm) ? $dm : $dm[0];
            $mid = $this->part1 !== null ? $this->part1 : "\$";
            $args = [];
            foreach (is_string($dm) ? [] : array_slice($dm, 1) as $arg) {
                $args[] = $sv->newv(str_replace("\$", $mid, $arg));
            }
            $this->default_value = $this->conf->ims()->default_itext($id, "", ...$args);
        }
        return $this->default_value;
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
            if ($this->placeholder === $v) {
                $v = "";
            }
            return $this->_tclass->parse_reqv($v, $this, $sv);
        } else {
            throw new ErrorException("Don't know how to parse_reqv {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return string */
    function base_unparse_reqv($v) {
        if ($this->_tclass) {
            return $this->_tclass->unparse_reqv($v, $this);
        } else {
            return (string) $v;
        }
    }

    /** @param mixed $jv
     * @return null|int|string */
    function convert_jsonv($jv, SettingValues $sv) {
        if ($this->_tclass) {
            if (is_string($jv)) {
                $jv = trim($jv);
                if ($this->placeholder === $jv) {
                    $jv = "";
                }
            }
            return $this->_tclass->convert_jsonv($jv, $this, $sv);
        } else {
            throw new ErrorException("Don't know how to convert_jsonv {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return mixed */
    function base_unparse_jsonv($v) {
        if ($this->_tclass) {
            return $this->_tclass->unparse_jsonv($v, $this);
        } else {
            return $v;
        }
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
