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
    /** @var ?string */
    public $group;
    /** @var ?list<string> */
    public $tags;
    /** @var null|int|float */
    public $order;
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
        "group" => "is_string",
        "ifnonempty" => "is_string",
        "internal" => "is_bool",
        "json_values" => "is_array",
        "order" => "is_number",
        "parser_class" => "is_string",
        "placeholder" => "is_string",
        "required" => "is_bool",
        "size" => "is_int",
        "subtype" => "is_string",
        "title" => "is_string",
        "title_pattern" => "is_string",
        "tags" => "is_string_list",
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
        if (!$this->group && !$this->internal) {
            trigger_error("setting {$j->name}.group missing");
        }

        if (!$this->type && $this->parser_class) {
            $this->type = "special";
        } else if ((!$this->type && !$this->internal) || $this->type === "none") {
            trigger_error("setting {$j->name}.type missing");
        }
        if (($this->_tclass = Sitype::get($conf, $this->type, $this->subtype))) {
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

    /** @return array<string,string> */
    function hoturl_param() {
        $param = ["group" => $this->group];
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
        if ($this->hashid !== false
            && (!$this->group || $sv->canonical_page === $this->group)) {
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
    function parse_vstr($reqv, SettingValues $sv) {
        if ($reqv === null) {
            return $this->_tclass ? $this->_tclass->parse_null_vstr($this) : null;
        } else if ($this->_tclass) {
            $v = trim($reqv);
            if ($this->placeholder === $v) {
                $v = "";
            }
            return $this->_tclass->parse_vstr($v, $this, $sv);
        } else {
            throw new ErrorException("Don't know how to parse_vstr {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return string */
    function base_unparse_reqv($v) {
        if ($this->_tclass) {
            return $this->_tclass->unparse_vstr($v, $this);
        } else {
            return (string) $v;
        }
    }
}


class SettingInfoSet {
    /** @var ComponentSet
     * @readonly */
    private $cs;
    /** @var array<string,Si> */
    private $map = [];
    /** @var array<string,list<object>> */
    private $xmap = [];
    /** @var list<string|list<string|object>> */
    private $xlist = [];
    /** @var array<string,?string> */
    private $canonpage = ["none" => null];

    function __construct(Conf $conf) {
        $this->cs = new ComponentSet($conf->root_user(), ["etc/settinggroups.json"], $conf->opt("settingGroups"));
        expand_json_includes_callback(["etc/settinginfo.json"], [$this, "_add_item"]);
        if (($olist = $conf->opt("settingInfo"))) {
            expand_json_includes_callback($olist, [$this, "_add_item"]);
        }
    }

    function _add_item($v, $k, $landmark) {
        if (isset($v->name_pattern)) {
            $parts = [];
            $pos = 0;
            while (($pos1 = strpos($v->name_pattern, '$', $pos)) !== false) {
                $pos2 = $pos1 + strspn($v->name_pattern, '$', $pos1);
                assert($pos2 - $pos1 === count($parts) / 2 + 1);
                $parts[] = substr($v->name_pattern, $pos, $pos1 - $pos);
                $parts[] = "";
                $pos = $pos2;
            }
            $parts[] = substr($v->name_pattern, $pos);
            $v->parts = $parts;
            $i = 0;
            while ($i !== count($this->xlist) && $this->xlist[$i] !== $parts[0]) {
                $i += 2;
            }
            if ($i === count($this->xlist)) {
                array_push($this->xlist, $parts[0], []);
            }
            array_push($this->xlist[$i + 1], $parts[count($parts) - 1], $v);
        } else {
            assert(is_string($v->name));
            $this->xmap[$v->name][] = $v;
        }
        return true;
    }

    /** @param string $name
     * @param list<string> $parts
     * @return ?list<string> */
    private function _match_parts($name, $parts) {
        $nparts = count($parts);
        $pos = strlen($parts[0]);
        $result = [$parts[0]];
        for ($i = 1; $i !== $nparts; $i += 2) {
            if ($i === $nparts - 2) {
                $npos = strlen($name) - strlen($parts[$i + 1]);
            } else {
                $npos = strpos($name, $parts[$i + 1], $pos);
            }
            if ($npos === false
                || $npos < $pos
                || ($m = substr($name, $pos, $npos - $pos)) === ""
                || strpos($m, "__") !== false) {
                return null;
            }
            $result[] = $m;
            $result[] = $parts[$i + 1];
            $pos += strlen($m) + strlen($parts[$i + 1]);
        }
        return $result;
    }

    /** @param string $name
     * @param string $prefix
     * @param list<string|object> $items */
    private function _expand($name, $prefix, $items) {
        $plen = strlen($prefix);
        $nlen = strlen($name);
        $nitems = count($items);
        for ($i = 0; $i !== $nitems; $i += 2) {
            $slen = strlen($items[$i]);
            if ($plen + strlen($items[$i]) < $nlen
                && str_ends_with($name, $items[$i])
                && ($parts = $this->_match_parts($name, $items[$i + 1]->parts))) {
                $jx = clone $items[$i + 1];
                $jx->name = $name;
                $jx->parts = $parts;
                $this->xmap[$name][] = $jx;
            }
        }
    }

    /** @param string $name
     * @return ?Si */
    function get($name) {
        if (!array_key_exists($name, $this->map)) {
            // expand patterns
            $nlen = strlen($name);
            for ($i = 0; $i !== count($this->xlist); $i += 2) {
                if (str_starts_with($name, $this->xlist[$i])) {
                    $this->_expand($name, $this->xlist[$i], $this->xlist[$i + 1]);
                }
            }
            // create Si
            $cs = $this->cs;
            $jx = $cs->conf->xt_search_name($this->xmap, $name, $cs->viewer);
            if ($jx) {
                Conf::xt_resolve_require($jx);
                if (($group = $jx->group ?? null)) {
                    if (!array_key_exists($group, $this->canonpage)) {
                        $this->canonpage[$group] = $cs->canonical_group($group) ?? $group;
                    }
                    $jx->group = $this->canonpage[$group];
                }
            }
            $this->map[$name] = $jx ? new Si($cs->conf, $jx) : null;
        }
        return $this->map[$name];
    }
}
