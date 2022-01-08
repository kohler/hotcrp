<?php
// si.php -- HotCRP conference settings information class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Si {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var string */
    public $name;
    /** @var ?array{string,string,string} */
    public $split_name;
    /** @var string */
    public $json_name;
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
    /** @var ?string */
    public $type;
    /** @var bool */
    public $internal = false;
    /** @var int */
    public $storage_type;
    /** @var ?string */
    private $storage;
    /** @var bool */
    public $optional = false;
    public $values;
    public $json_values;
    /** @var ?int */
    public $size;
    /** @var ?string */
    public $placeholder;
    /** @var class-string */
    public $parser_class;
    /** @var bool */
    public $disabled = false;
    public $invalid_value;
    public $default_value;
    /** @var ?bool */
    public $autogrow;
    /** @var ?string */
    public $ifnonempty;
    /** @var null|string|list<string> */
    public $default_message;

    /** @var array<string,bool> */
    static public $option_is_value = [];

    const SI_NONE = 0;
    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;
    const SI_NEGATE = 16;

    static private $type_storage = [
        "emailheader" => self::SI_DATA,
        "emailstring" => self::SI_DATA,
        "htmlstring" => self::SI_DATA,
        "simplestring" => self::SI_DATA,
        "string" => self::SI_DATA,
        "tag" => self::SI_DATA,
        "tagbase" => self::SI_DATA,
        "taglist" => self::SI_DATA,
        "tagselect" => self::SI_DATA,
        "urlstring" => self::SI_DATA
    ];

    static private $key_storage = [
        "autogrow" => "is_bool",
        "disabled" => "is_bool",
        "group" => "is_string",
        "ifnonempty" => "is_string",
        "internal" => "is_bool",
        "invalid_value" => "is_string",
        "json_values" => "is_array",
        "optional" => "is_bool",
        "order" => "is_number",
        "parser_class" => "is_string",
        "placeholder" => "is_string",
        "size" => "is_int",
        "title" => "is_string",
        "title_pattern" => "is_string",
        "tags" => "is_string_list",
        "type" => "is_string",
        "values" => "is_array"
    ];

    private function store($key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey) && call_user_func($typecheck, $j->$jkey)) {
            $this->$key = $j->$jkey;
        } else if (isset($j->$jkey)) {
            trigger_error("setting {$j->name}.$jkey format error");
        }
    }

    function __construct(Conf $conf, $j) {
        $this->conf = $conf;
        $this->name = $this->json_name = $this->title = $j->name;
        if (isset($j->json_name)
            && ($j->json_name === false || is_string($j->json_name))) {
            $this->json_name = $j->json_name;
        }
        if (isset($j->split_name)) {
            $this->store("split_name", $j, "split_name", "is_string_list");
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

        $s = $this->storage ?? $this->name;
        $dot = strpos($s, ".");
        if ($dot === 3 && substr($s, 0, 3) === "opt") {
            $this->storage_type = self::SI_DATA | self::SI_OPT;
        } else if ($dot === 3 && substr($s, 0, 3) === "ova") {
            $this->storage_type = self::SI_VALUE | self::SI_OPT;
            $this->storage = "opt." . substr($s, 4);
        } else if ($dot === 3 && substr($s, 0, 3) === "val") {
            $this->storage_type = self::SI_VALUE | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if ($dot === 3 && substr($s, 0, 3) === "dat") {
            $this->storage_type = self::SI_DATA | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if ($dot === 6 && substr($s, 0, 6) === "negval") {
            $this->storage_type = self::SI_VALUE | self::SI_SLICE | self::SI_NEGATE;
            $this->storage = substr($s, 7);
        } else if ($this->storage === "none") {
            $this->storage_type = self::SI_NONE;
        } else if (isset(self::$type_storage[$this->type])) {
            $this->storage_type = self::$type_storage[$this->type];
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

        // defaults for size, placeholder
        if ($this->type && str_ends_with($this->type, "date")) {
            $this->size = $this->size ?? 32;
            $this->placeholder = $this->placeholder ?? "N/A";
        } else if ($this->type === "grace") {
            $this->size = $this->size ?? 15;
            $this->placeholder = $this->placeholder ?? "none";
        }

        // resolve extension
        if (isset($this->split_name)) {
            $this->storage = $this->_expand_split($this->storage);
            $this->hashid = $this->_expand_split($this->hashid);
        }
    }

    /** @param null|false|string $s
     * @return null|false|string
     * @suppress PhanTypeArraySuspiciousNullable */
    function _expand_split($s) {
        if (is_string($s)) {
            $p = 0;
            while (($p = strpos($s, "\$", $p)) !== false) {
                if ($p === strlen($s) - 1 || $s[$p + 1] !== "{") {
                    $s = substr($s, 0, $p) . $this->split_name[1] . substr($s, $p + 1);
                    $p += strlen($this->split_name[1]);
                } else {
                    ++$p;
                }
            }
        }
        return $s;
    }

    /** @param string $s
     * @param ?SettingValues $sv
     * @return ?string */
    private function expand_pattern($s, $sv) {
        $pos = 0;
        $len = strlen($s);
        while ($pos < $len
               && ($dollar = strpos($s, "\$", $pos)) !== false) {
            if ($dollar + 1 < $len
                && $s[$dollar + 1] === "{") {
                $rbrace = SearchSplitter::span_balanced_parens($s, $dollar + 2, "");
                if ($rbrace < $len
                    && $s[$rbrace] === "}"
                    && ($r = $this->expand_pattern_call(substr($s, $dollar + 2, $rbrace - $dollar - 2), $sv)) !== null) {
                    $s = substr($s, 0, $dollar) . $r . substr($s, $rbrace + 1);
                    $pos = $dollar + strlen($r);
                } else {
                    return null;
                }
            } else if ($this->split_name) {
                $s = substr($s, 0, $dollar) . $this->split_name[1] . substr($s, $dollar + 1);
                $pos = $dollar + strlen($this->split_name[1]);
            } else {
                $pos = $dollar + 1;
            }
        }
        return $s;
    }

    /** @param string $call
     * @param ?SettingValues $sv
     * @return ?string */
    private function expand_pattern_call($call, $sv) {
        $r = null;
        if (($f = $this->expand_pattern(trim($call), $sv)) !== null) {
            if (str_starts_with($f, "uc ")) {
                $r = ucfirst(trim(substr($f, 3)));
            } else if (str_starts_with($f, "sv ")) {
                $r = $sv->curv(trim(substr($f, 3)));
            }
        }
        return $r;
    }

    /** @param ?SettingValues $sv
     * @return ?string */
    function title($sv = null) {
        if ($this->title_pattern
            && ($title = $this->expand_pattern($this->title_pattern, $sv)) !== null) {
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


    /** @return bool */
    function is_date() {
        return $this->type && str_ends_with($this->type, "date");
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


    /** @param ?string $reqv
     * @return null|int|string */
    function base_parse_reqv($reqv, SettingValues $sv) {
        if ($reqv === null) {
            if ($this->type === "cdate" || $this->type === "checkbox") {
                return 0;
            } else {
                return null;
            }
        }

        $v = trim($reqv);
        if (($this->placeholder !== null && $this->placeholder === $v)
            || ($this->invalid_value && $this->invalid_value === $v)) {
            $v = "";
        }

        if ($this->type === "checkbox") {
            return $v != "" ? 1 : 0;
        } else if ($this->type === "cdate") {
            if ($v != "") {
                $v = $sv->si_oldv($this);
                return $v > 0 ? $v : Conf::$now;
            } else {
                return 0;
            }
        } else if ($this->type === "date" || $this->type === "ndate") {
            if ($v === ""
                || $v === "0"
                || strcasecmp($v, "N/A") === 0
                || strcasecmp($v, "same as PC") === 0
                || ($this->type !== "ndate" && strcasecmp($v, "none") === 0)) {
                return -1;
            } else if (strcasecmp($v, "none") === 0) {
                return 0;
            } else if (($v = $this->conf->parse_time($v)) !== false) {
                return $v;
            } else {
                $sv->error_at($this, "Please enter a valid date");
                return null;
            }
        } else if ($this->type === "grace") {
            if (($v = SettingParser::parse_interval($v)) !== false) {
                return intval($v);
            } else {
                $sv->error_at($this, "Please enter a valid grace period");
                return null;
            }
        } else if ($this->type === "int" || $this->type === "zint") {
            if (preg_match("/\\A[-+]?[0-9]+\\z/", $v)) {
                return intval($v);
            } else if ($v == "" && $this->placeholder !== null) {
                return 0;
            } else {
                $sv->error_at($this, "Please enter a whole number");
                return null;
            }
        } else if ($this->type === "string") {
            // Avoid storing the default message in the database
            if (substr($this->name, 0, 9) == "mailbody_") {
                $t = $sv->expand_mail_template(substr($this->name, 9), true);
                $v = cleannl($v);
                if ($t["body"] === $v) {
                    return "";
                }
            }
            return $v;
        } else if ($this->type === "simplestring") {
            return simplify_whitespace($v);
        } else if ($this->type === "tag"
                   || $this->type === "tagbase"
                   || $this->type === "tagselect") {
            $v = trim($v);
            if ($v === "" && $this->optional) {
                return "";
            }
            $tagger = new Tagger($sv->user);
            if (($v = $tagger->check($v, $this->type === "tagbase" ? Tagger::NOVALUE : 0))) {
                return $v;
            } else {
                $sv->error_at($this, "<5>" . $tagger->error_html());
                return null;
            }
        } else if ($this->type === "emailheader") {
            $mt = new MimeText;
            $v = $mt->encode_email_header("", $v);
            if ($v !== false) {
                return $v == "" ? "" : MimeText::decode_header($v);
            } else {
                $sv->append_item_at($this, $mt->mi);
                return null;
            }
        } else if ($this->type === "emailstring") {
            $v = trim($v);
            if ($v === "" && $this->optional) {
                return "";
            } else if (validate_email($v) || $v === $sv->oldv($this->name)) {
                return $v;
            } else {
                $sv->error_at($this, "Please enter a valid email address");
                return null;
            }
        } else if ($this->type === "urlstring") {
            $v = trim($v);
            if (($v === "" && $this->optional)
                || preg_match('/\A(?:https?|ftp):\/\/\S+\z/', $v)) {
                return $v;
            } else {
                $sv->error_at($this, "Please enter a valid URL");
                return null;
            }
        } else if ($this->type === "htmlstring") {
            $ch = CleanHTML::basic();
            if (($v = $ch->clean($v)) !== false) {
                if (str_starts_with($this->storage_name(), "msg.")
                    && $v === $sv->si_message_default($this))
                    return "";
                return $v;
            } else {
                $sv->error_at($this, "<5>{$ch->last_error}");
                return null;
            }
        } else if ($this->type === "radio") {
            foreach ($this->values as $allowedv) {
                if ((string) $allowedv === $v)
                    return $allowedv;
            }
            $sv->error_at($this, "Please enter a valid choice");
            return null;
        } else {
            throw new Error("Don't know how to base_parse_reqv {$this->name}.");
        }
    }

    /** @param null|int|string $v
     * @return string */
    function base_unparse_reqv($v) {
        if ($this->type === "cdate" || $this->type === "checkbox") {
            return $v ? "1" : "";
        } else if ($this->is_date()) {
            if ($v === null) {
                return "";
            } else if ($this->placeholder !== "N/A"
                       && $this->placeholder !== "none"
                       && $v === 0) {
                return "none";
            } else if ($v <= 0) {
                return "";
            } else if ($v === 1) {
                return "now";
            } else {
                return $this->conf->parseableTime($v, true);
            }
        } else if ($this->type === "grace") {
            if ($v === null || $v <= 0 || !is_numeric($v)) {
                return "none";
            } else if ($v % 3600 === 0) {
                return ($v / 3600) . " hr";
            } else if ($v % 60 === 0) {
                return ($v / 60) . " min";
            } else {
                return sprintf("%d:%02d", intval($v / 60), $v % 60);
            }
        } else {
            return (string) $v;
        }
    }

    /** @param null|int|string $v
     * @return mixed */
    function base_unparse_json($v) {
        if ($this->type === "checkbox" || $this->type === "cdate") {
            return !!$v;
        } else if ($this->type === "date" || $this->type === "ndate") {
            if ($v > 0) {
                return $this->conf->parseableTime($v, true);
            } else {
                return false;
            }
        } else if ($this->type === "grace") {
            if ($v > 0) {
                return $this->base_unparse_reqv($v);
            } else {
                return false;
            }
        } else if ($this->type === "int") {
            return $v > 0 ? (int) $v : false;
        } else if ($this->type === "zint") {
            return (int) $v;
        } else if ($this->type === "string"
                   || $this->type === "simplestring"
                   || $this->type === "tag"
                   || $this->type === "tagbase"
                   || $this->type === "tagselect"
                   || $this->type === "emailheader"
                   || $this->type === "emailstring"
                   || $this->type === "urlstring"
                   || $this->type === "htmlstring") {
            return (string) $v !== "" ? $v : false;
        } else if ($this->type === "radio") {
            $pos = array_search($v, $this->values);
            if ($pos !== false && $this->json_values && isset($this->json_values[$pos])) {
                return $this->json_values[$pos];
            } else {
                return $v;
            }
        } else {
            return $v;
        }
    }
}


class SettingInfoSet {
    /** @var GroupedExtensions
     * @readonly */
    private $gxt;
    /** @var array<string,Si> */
    private $map = [];
    /** @var array<string,list<object>> */
    private $xmap = [];
    /** @var list<string|list<string|object>> */
    private $xlist = [];
    /** @var array<string,?string> */
    private $canonpage = ["none" => null];

    function __construct(Conf $conf) {
        $this->gxt = new GroupedExtensions($conf->root_user(), ["etc/settinggroups.json"], $conf->opt("settingGroups"));
        expand_json_includes_callback(["etc/settinginfo.json"], [$this, "_add_item"]);
        if (($olist = $conf->opt("settingInfo"))) {
            expand_json_includes_callback($olist, [$this, "_add_item"]);
        }
    }

    function _add_item($v, $k, $landmark) {
        if (isset($v->extensible) && $v->extensible && !isset($v->name_pattern)) {
            $v->name_pattern = "{$v->name}_\$";
        }
        if (isset($v->name_pattern)) {
            $pos = strpos($v->name_pattern, "\$");
            assert($pos !== false);
            $prefix = substr($v->name_pattern, 0, $pos);
            $suffix = substr($v->name_pattern, $pos + 1);
            $i = 0;
            while ($i !== count($this->xlist) && $this->xlist[$i] !== $prefix) {
                $i += 2;
            }
            if ($i === count($this->xlist)) {
                array_push($this->xlist, $prefix, []);
            }
            array_push($this->xlist[$i + 1], $suffix, $v);
        } else {
            assert(is_string($v->name));
            $this->xmap[$v->name][] = $v;
        }
        return true;
    }

    /** @param string $name
     * @return ?Si */
    function get($name) {
        if (!array_key_exists($name, $this->map)) {
            // expand patterns
            $nlen = strlen($name);
            for ($i = 0; $i !== count($this->xlist); $i += 2) {
                if (str_starts_with($name, $this->xlist[$i])) {
                    $plen = strlen($this->xlist[$i]);
                    $list = $this->xlist[$i + 1];
                    for ($j = 0; $j !== count($list); $j += 2) {
                        $slen = strlen($list[$j]);
                        if ($plen + $slen < $nlen
                            && str_ends_with($name, $list[$j])) {
                            $jx = clone $list[$j + 1];
                            $jx->name = $name;
                            $jx->split_name = [
                                $this->xlist[$i],
                                substr($name, $plen, $nlen - $slen - $plen),
                                $list[$j]
                            ];
                            $this->xmap[$name][] = $jx;
                        }
                    }
                }
            }
            // create Si
            $gxt = $this->gxt;
            $jx = $gxt->conf->xt_search_name($this->xmap, $name, $gxt->viewer);
            if ($jx) {
                Conf::xt_resolve_require($jx);
                if (($group = $jx->group ?? null)) {
                    if (!array_key_exists($group, $this->canonpage)) {
                        $this->canonpage[$group] = $gxt->canonical_group($group) ?? $group;
                    }
                    $jx->group = $this->canonpage[$group];
                }
            }
            $this->map[$name] = $jx ? new Si($gxt->conf, $jx) : null;
        }
        return $this->map[$name];
    }
}
