<?php
// settingvalues.php -- HotCRP conference settings management helper classes
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

// setting information
class Si {
    /** @var string */
    public $name;
    /** @var string */
    public $base_name;
    /** @var string */
    public $json_name;
    /** @var string */
    private $title;
    /** @var ?string */
    public $title_pattern;
    /** @var ?string */
    public $group;
    /** @var ?string */
    public $canonical_page;
    /** @var ?list<string> */
    public $tags;
    /** @var null|int|float */
    public $position;
    /** @var null|false|string */
    public $hashid;
    /** @var ?string */
    public $type;
    /** @var bool */
    public $internal = false;
    /** @var int */
    public $extensible;
    /** @var ?Si */
    public $parent;
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
    /** @var ?class-string */
    public $parser_class;
    /** @var class-string */
    public $validator_class;
    /** @var bool */
    public $disabled = false;
    public $invalid_value;
    public $default_value;
    /** @var ?bool */
    public $autogrow;
    /** @var ?string */
    public $ifnonempty;
    /** @var ?string */
    public $message_context_setting;
    /** @var ?string */
    public $date_backup;

    /** @var array<string,bool> */
    static public $option_is_value = [];

    const SI_NONE = 0;
    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;
    const SI_NEGATE = 16;

    const X_NO = 0;
    const X_SIMPLE = 1;
    const X_WORD = 2;

    static private $type_storage = [
        "emailheader" => self::SI_DATA,
        "emailstring" => self::SI_DATA,
        "htmlstring" => self::SI_DATA,
        "simplestring" => self::SI_DATA,
        "string" => self::SI_DATA,
        "tag" => self::SI_DATA,
        "tagbase" => self::SI_DATA,
        "taglist" => self::SI_DATA,
        "urlstring" => self::SI_DATA
    ];

    static private $key_storage = [
        "autogrow" => "is_bool",
        "canonical_page" => "is_string",
        "date_backup" => "is_string",
        "disabled" => "is_bool",
        "group" => "is_string",
        "ifnonempty" => "is_string",
        "internal" => "is_bool",
        "invalid_value" => "is_string",
        "json_values" => "is_array",
        "message_context_setting" => "is_string",
        "optional" => "is_bool",
        "parser_class" => "is_string",
        "placeholder" => "is_string",
        "position" => "is_number",
        "size" => "is_int",
        "title" => "is_string",
        "title_pattern" => "is_string",
        "tags" => "is_string_list",
        "type" => "is_string",
        "validator_class" => "is_string",
        "values" => "is_array"
    ];

    private function store($key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey) && call_user_func($typecheck, $j->$jkey)) {
            $this->$key = $j->$jkey;
        } else if (isset($j->$jkey)) {
            trigger_error("setting {$j->name}.$jkey format error");
        }
    }

    function __construct($j) {
        if (preg_match('/\.|_(?:\$|n\d*|m?\d+)\z/', $j->name)) {
            trigger_error("setting {$j->name} name format error");
        }
        $this->name = $this->base_name = $this->json_name = $this->title = $j->name;
        if (isset($j->json_name)
            && ($j->json_name === false || is_string($j->json_name))) {
            $this->json_name = $j->json_name;
        }
        foreach ((array) $j as $k => $v) {
            if (isset(self::$key_storage[$k])) {
                $this->store($k, $j, $k, self::$key_storage[$k]);
            }
        }
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
        $this->extensible = self::X_NO;
        if (isset($j->extensible)) {
            if ($j->extensible === true || $j->extensible === "simple") {
                $this->extensible = self::X_SIMPLE;
            } else if ($j->extensible === "word") {
                $this->extensible = self::X_WORD;
            } else if ($j->extensible !== false) {
                trigger_error("setting {$j->name}.extensible format error");
            }
        }
        if (!$this->group && !$this->internal) {
            trigger_error("setting {$j->name}.group missing");
        }

        if (!$this->type && $this->parser_class) {
            $this->type = "special";
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
    }

    /** @param string $suffix
     * @param ?object $overrides
     * @return Si */
    function extend($suffix, $overrides) {
        assert(str_starts_with($suffix, "_"));
        $si = clone $this;
        $si->parent = $this;
        $si->name .= $suffix;
        if ($this->storage) {
            $si->storage .= $suffix;
        }
        if ($this->message_context_setting
            && str_starts_with($this->message_context_setting, "+")) {
            $si->message_context_setting .= $suffix;
        }
        if ($this->hashid) {
            $si->hashid = null;
        }
        if ($overrides) {
            foreach (["title", "placeholder", "size", "group", "tags"] as $k) {
                if (isset($overrides->$k)) {
                    $si->store($k, $overrides, $k, self::$key_storage[$k]);
                }
            }
        }
        return $si;
    }

    /** @return string */
    function prefix() {
        return ($this->parent ?? $this)->name;
    }

    /** @return string */
    function suffix() {
        if ($this->parent) {
            return substr($this->name, strlen($this->parent->name));
        } else {
            return "";
        }
    }

    /** @return ?string */
    function title(SettingValues $sv = null) {
        if ($this->title_pattern) {
            $ok = true;
            $title = preg_replace_callback('/\$\{.*?\}/', function ($m) use ($sv, &$ok) {
                $x = substr($m[0], 2, strlen($m[0]) - 3);
                $t = null;
                if ($x === "suffix" && $this->parent) {
                    $t = substr($this->suffix(), 1);
                } else if ($x === "capsuffix" && $this->parent) {
                    $t = ucwords(substr($this->suffix(), 1));
                } else if (str_starts_with($x, "req.") && $sv) {
                    if (str_ends_with($x, "_\$") && ($suffix = $this->suffix())) {
                        $t = $sv->reqv(substr($x, 4, strlen($x) - 6) . $suffix);
                    } else {
                        $t = $sv->reqv(substr($x, 4));
                    }
                }
                if ($t !== null && $t !== "") {
                    return $t;
                } else {
                    $ok = false;
                    return "";
                }
            }, $this->title_pattern);
            if ($ok) {
                return $title;
            }
        }
        return $this->title;
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
    function storage() {
        return $this->storage ?? $this->name;
    }

    /** @return bool */
    function active_value() {
        return !$this->internal
            && !$this->disabled
            && $this->type
            && $this->type !== "none";
    }


    /** @return bool */
    function is_date() {
        return str_ends_with($this->type, "date");
    }

    /** @return array<string,string> */
    function hoturl_param($conf) {
        $param = ["group" => $this->canonical_page];
        if ($this->hashid !== false) {
            $param["#"] = $this->hashid ?? $this->name;
        }
        return $param;
    }

    /** @param Conf $conf
     * @return string */
    function hoturl($conf) {
        return $conf->hoturl("settings", $this->hoturl_param($conf));
    }

    /** @param SettingValues $sv
     * @return string */
    function sv_hoturl($sv) {
        if ($this->canonical_page !== null
            && $this->canonical_page === $sv->canonical_page) {
            return "#" . urlencode($this->hashid ? : $this->name);
        } else {
            return $this->hoturl($sv->conf);
        }
    }


    /** @return array<string,Si> */
    static function make_si_map(Conf $conf) {
        $last_problem = 0;
        $sis = [];
        $hook = function ($v, $k, $landmark) use (&$sis, &$last_problem) {
            if (is_object($v) && isset($v->name) && is_string($v->name)) {
                $sis[] = $v;
                return true;
            } else {
                return false;
            }
        };

        expand_json_includes_callback(["etc/settinginfo.json"], $hook);
        if (($olist = $conf->opt("settingInfo"))) {
            expand_json_includes_callback($olist, $hook);
        }
        usort($sis, function ($a, $b) {
            return strcmp($a->name, $b->name) ? : Conf::xt_priority_compare($a, $b);
        });

        $gex = new GroupedExtensions($conf->root_user(), ["etc/settinggroups.json"], $conf->opt("settingGroups"));
        $canonpage = ["none" => null];

        $sim = $overrides = [];
        $nall = count($sis);
        for ($i = 0; $i < $nall; ++$i) {
            $j = $sis[$i];
            while ($i + 1 < $nall
                   && isset($j->merge)
                   && $j->merge
                   && $j->name === $sis[$i + 1]->name) {
                $overlay = $j;
                unset($overlay->merge);
                $j = $sis[$i + 1];
                object_replace_recursive($j, $overlay);
                ++$i;
            }
            if ($conf->xt_allowed($j) && !isset($sim[$j->name])) {
                Conf::xt_resolve_require($j);
                if (isset($j->group)) {
                    if (!array_key_exists($j->group, $canonpage)) {
                        $canonpage[$j->group] = $gex->canonical_group($j->group);
                    }
                    $j->canonical_page = $canonpage[$j->group];
                }
                if (($j->extensible ?? null) !== "override") {
                    $sim[$j->name] = new Si($j);
                } else {
                    $overrides[] = $j;
                }
            }
        }

        foreach ($overrides as $j) {
            if (preg_match('/\A(.*)(_[^_\s]+)\z/', $j->name, $m)
                && ($base = $sim[$m[1]] ?? null)
                && $base->extensible) {
                $sim[$j->name] = $base->extend($m[2], $j);
            }
        }

        uasort($sim, "Conf::xt_position_compare");
        return $sim;
    }

    /** @return array<string,Si> */
    static function si_map(Conf $conf) {
        if (empty($conf->_setting_info)) {
            $conf->_setting_info = self::make_si_map($conf);
        }
        return $conf->_setting_info;
    }

    /** @param string $name
     * @return ?Si */
    static function get(Conf $conf, $name) {
        if (empty($conf->_setting_info)) {
            $conf->_setting_info = self::make_si_map($conf);
        }
        if (isset($conf->_setting_info[$name])) {
            return $conf->_setting_info[$name];
        } else if (preg_match('/\A(.*)(_[^_\s]+)\z/', $name, $m)
                   && isset($conf->_setting_info[$m[1]])) {
            $base_si = $conf->_setting_info[$m[1]];
            if (!$base_si->extensible
                || $base_si->parent
                || ($base_si->extensible === self::X_SIMPLE
                    && !preg_match('/\A_(?:\$|n\d*|m?\d+)\z/', $m[2]))) {
                if ($base_si->extensible !== self::X_NO) {
                    error_log("$name: cloning non-extensible setting {$base_si->name}\n" . debug_string_backtrace());
                }
                return null;
            }
            $si = $conf->_setting_info[$name] = $base_si->extend($m[2], null);
            return $si;
        } else {
            return null;
        }
    }
}

class SettingParser {
    function parse(SettingValues $sv, Si $si) {
        return false;
    }
    function validate(SettingValues $sv, Si $si) {
        return false;
    }
    function unparse_json(SettingValues $sv, Si $si, $j) {
    }
    function save(SettingValues $sv, Si $si) {
    }

    /** @param string $v
     * @return -1|float|false */
    static function parse_interval($v) {
        $t = 0;
        $v = trim($v);
        if ($v === ""
            || strtoupper($v) === "N/A"
            || strtoupper($v) === "NONE"
            || $v === "0") {
            return -1;
        } else if (ctype_digit($v)) {
            return ((float) $v) * 60;
        } else if (preg_match('/\A\s*([\d]+):(\d+\.?\d*|\.\d+)\s*\z/', $v, $m)) {
            return ((float) $m[1]) * 60 + (float) $m[2];
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*y(?:ears?|rs?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 365;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*mo(?:nths?|ns?|s|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 30;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*w(?:eeks?|ks?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 7;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*d(?:ays?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*h(?:rs?|ours?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*m(?:inutes?|ins?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 60;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*s(?:econds?|ecs?|)(?![a-z])/i', $v, $m)) {
            $t += (float) $m[1];
            $v = substr($v, strlen($m[0]));
        }
        if (trim($v) == "") {
            return $t;
        } else {
            return false;
        }
    }
}

class SettingValues extends MessageSet {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ?string */
    public $canonical_page;
    /** @var list<string|bool> */
    private $perm;
    /** @var bool */
    private $all_perm;

    private $parsers = [];
    /** @var list<Si> */
    private $validate_si = [];
    /** @var list<Si> */
    private $saved_si = [];
    /** @var list<array{?string,callable()}> */
    private $cleanup_callbacks = [];
    public $need_lock = [];
    /** @var array<string,int> */
    private $_table_lock = [];
    /** @var associative-array<string,true> */
    private $diffs = [];
    /** @var associative-array<string,true> */
    private $invalidate_caches = [];

    public $req = [];
    public $req_files = [];
    public $savedv = [];
    /** @var array<string,null|int|string> */
    private $explicit_oldv = [];
    private $hint_status = [];
    /** @var array<string,list<string>> */
    private $req_has_suffixes = [];
    /** @var ?Mailer */
    private $null_mailer;

    /** @var ?GroupedExtensions */
    private $_gxt;

    function __construct(Contact $user) {
        parent::__construct();
        $this->conf = $user->conf;
        $this->user = $user;
        $this->all_perm = $user->privChair;
        foreach (Tagger::split_unpack($user->contactTags) as $ti) {
            if (strcasecmp($ti[0], "perm:write-setting") === 0) {
                $this->all_perm = $ti[1] >= 0;
            } else if (stri_starts_with($ti[0], "perm:write-setting:")) {
                $this->perm[] = substr($ti[0], strlen("perm:write-setting:"));
                $this->perm[] = $ti[1] >= 0;
            }
        }
    }

    /** @param Qrequest|array<string,string|int|float> $qreq */
    static function make_request(Contact $user, $qreq) {
        $sv = new SettingValues($user);
        foreach ($qreq as $k => $v) {
            $sv->set_req($k, (string) $v);
        }
        if ($qreq instanceof Qrequest) {
            foreach ($qreq->files() as $f => $finfo)
                $sv->req_files[$f] = $finfo;
        }
        return $sv;
    }

    /** @param string $k
     * @param string $v */
    function set_req($k, $v) {
        $this->req[$k] = $v;
        if (preg_match('/\A(?:has_)?(\S+?)(|_n\d*|_m?\d+)\z/', $k, $m)) {
            if (!isset($this->req_has_suffixes[$m[1]])) {
                $this->req_has_suffixes[$m[1]] = [$m[2]];
            } else if (!in_array($m[2], $this->req_has_suffixes[$m[1]])) {
                $this->req_has_suffixes[$m[1]][] = $m[2];
            }
        }
    }

    /** @param string $k */
    function unset_req($k) {
        unset($this->req[$k]);
    }

    function session_highlight() {
        foreach ($this->user->session("settings_highlight", []) as $f => $v) {
            $this->msg_at($f, null, $v);
        }
        $this->user->save_session("settings_highlight", null);
    }

    /** @return bool */
    function viewable_by_user() {
        for ($i = 0; $i !== count($this->perm ?? []); $i += 2) {
            if ($this->perm[$i + 1])
                return true;
        }
        return $this->all_perm;
    }


    /** @return GroupedExtensions */
    private function gxt() {
        if ($this->_gxt === null) {
            $this->_gxt = new GroupedExtensions($this->user, ["etc/settinggroups.json"], $this->conf->opt("settingGroups"));
            $this->_gxt->set_title_class("form-h")->set_section_class("form-section")
                ->set_context_args([$this]);
        }
        return $this->_gxt;
    }
    /** @param string $g
     * @return ?string */
    function canonical_group($g) {
        return $this->gxt()->canonical_group(strtolower($g));
    }
    /** @param string $g
     * @return ?string */
    function group_title($g) {
        $gj = $this->gxt()->get($g);
        return $gj && $gj->name === $gj->group ? $gj->title : null;
    }
    /** @param string $g
     * @return ?string */
    function group_hashid($g) {
        $gj = $this->gxt()->get($g);
        return $gj && isset($gj->hashid) ? $gj->hashid : null;
    }
    /** @param string $g
     * @return list<object> */
    function group_members($g) {
        return $this->gxt()->members(strtolower($g));
    }
    function crosscheck() {
        foreach ($this->gxt()->members("__crosscheck", "crosscheck_function") as $gj) {
            $this->gxt()->call_function($gj->crosscheck_function, $gj);
        }
    }
    /** @param string $g
     * @param bool $top */
    function render_group($g, $top = false) {
        $this->gxt()->render_group($g, $top);
    }
    /** @param ?string $classes
     * @param ?string $id */
    function render_open_section($classes = null, $id = null) {
        $this->gxt()->render_open_section($classes, $id);
    }
    /** @param string $title
     * @param ?string $id */
    function render_section($title, $id = null) {
        $this->gxt()->render_section($title, $id);
    }


    /** @return bool */
    function use_req() {
        return $this->has_error();
    }
    /** @param Si|string|null|list<Si|string> $field
     * @param string|null|false $html
     * @return MessageItem */
    function error_at($field, $html = null) {
        if (is_array($field)) {
            foreach ($field as $f) {
                $mi = $this->error_at($f, $html);
            }
        } else {
            $fname = $field instanceof Si ? $field->name : $field;
            $mi = parent::error_at($fname, $html);
        }
        return $mi ?? new MessageItem(null, "", MessageSet::ERROR);
    }
    /** @param Si|string|null|list<Si|string> $field
     * @param string|null|false $html
     * @return MessageItem */
    function warning_at($field, $html = null) {
        if (is_array($field)) {
            foreach ($field as $f) {
                $mi = $this->warning_at($f, $html);
            }
        } else {
            $fname = $field instanceof Si ? $field->name : $field;
            $mi = parent::warning_at($fname, $html);
        }
        return $mi ?? new MessageItem(null, "", MessageSet::WARNING);
    }
    /** @param MessageItem $mx */
    private function report_mx(&$msgs, &$lastmsg, $mx) {
        $t = $mx->message;
        if ($mx->status === MessageSet::WARNING) {
            $t = "Warning: " . $t;
        }
        $loc = null;
        if ($mx->field
            && ($si = Si::get($this->conf, $mx->field))
            && ($loc = $si->title_html($this))) {
            if ($si->hashid !== false) {
                $loc = Ht::link($loc, $si->sv_hoturl($this));
            }
        }
        if ($lastmsg && $lastmsg[0] === $t) {
            if ($lastmsg[1]) {
                $loc = $loc ? $lastmsg[1] . ", " . $loc : $lastmsg[1];
            }
            $msgs[count($msgs) - 1] = $loc ? $loc . ": " . $t : $t;
        } else {
            $msgs[] = $loc ? $loc . ": " . $t : $t;
        }
        $lastmsg = [$t, $loc];
    }
    function report($is_update = false) {
        $msgs = [];
        if ($is_update && $this->has_error()) {
            $msgs[] = "Your changes were not saved. Please fix these errors and try again.";
        }
        $lastmsg = null;
        foreach ($this->message_list() as $mx) {
            $this->report_mx($msgs, $lastmsg, $mx);
        }
        if (!empty($msgs)) {
            if ($this->has_error()) {
                Conf::msg_error($msgs, true);
            } else {
                Conf::msg_warning($msgs, true);
            }
        }
    }
    /** @return SettingParser */
    private function si_parser(Si $si) {
        $class = $si->parser_class ?? $si->validator_class;
        if (!isset($this->parsers[$class])) {
            $this->parsers[$class] = new $class($this, $si);
        }
        return $this->parsers[$class];
    }

    /** @param ?string $c1
     * @param ?string $c2
     * @return ?string */
    static function add_class($c1, $c2) {
        if ($c1 === null || $c1 === "") {
            return $c2;
        } else if ($c2 === null || $c2 === "") {
            return $c1;
        } else {
            return $c1 . " " . $c2;
        }
    }

    function label($name, $html, $label_js = []) {
        $name1 = is_array($name) ? $name[0] : $name;
        if (($label_js["class"] ?? null) === false
            || ($label_js["no_control_class"] ?? false)) {
            unset($label_js["no_control_class"]);
        } else {
            foreach (is_array($name) ? $name : array($name) as $n) {
                if (($sc = $this->control_class($n))) {
                    $label_js["class"] = self::add_class($sc, $label_js["class"] ?? null);
                    break;
                }
            }
        }
        $post = "";
        if (($pos = strpos($html, "<input")) !== false) {
            list($html, $post) = [substr($html, 0, $pos), substr($html, $pos)];
        }
        return Ht::label($html, $name1, $label_js) . $post;
    }
    /** @param Si|string $name
     * @param ?array<string,mixed> $js
     * @param ?string $type
     * @return array<string,mixed> */
    function sjs($name, $js = null, $type = null) {
        if ($name instanceof Si) {
            $si = $name;
            $name = $si->name;
        } else {
            $si = Si::get($this->conf, $name);
        }
        $x = ["id" => $name];
        if ($si) {
            if ($si->disabled) {
                $x["disabled"] = true;
            } else if (!$this->si_editable($si)) {
                if (in_array($type, ["checkbox", "radio", "select"], true)) {
                    $x["disabled"] = true;
                } else {
                    $x["readonly"] = true;
                }
            }
        }
        if ($this->use_req()
            && !isset($js["data-default-value"])
            && !isset($js["data-default-checked"])) {
            if ($si && $this->si_has_interest($si)) {
                $v = $this->si_oldv($si);
                $x["data-default-value"] = $this->si_unparse_value($v, $si);
            } else if (isset($this->explicit_oldv[$name])) {
                $x["data-default-value"] = $this->explicit_oldv[$name];
            }
        }
        foreach ($js ?? [] as $k => $v) {
            $x[$k] = $v;
        }
        if ($this->has_problem_at($name)) {
            $x["class"] = $this->control_class($name, $x["class"] ?? "");
        }
        return $x;
    }

    /** @return Si */
    function si($name) {
        if (($si = Si::get($this->conf, $name))) {
            return $si;
        } else {
            throw new Exception(caller_landmark(2) . ": Unknown setting “{$name}”");
        }
    }

    /** @param string $name
     * @return bool */
    function editable($name) {
        return $this->si_editable($this->si($name));
    }
    /** @return bool */
    function si_editable(Si $si) {
        $perm = $this->all_perm;
        if ($this->perm !== null) {
            for ($i = 0; $i !== count($this->perm); $i += 2) {
                if ($si->group === $this->perm[$i]
                    || ($si->tags !== null && in_array($this->perm[$i], $si->tags, true))) {
                    return $this->perm[$i + 1];
                } else if ($si->canonical_page !== $si->group
                           && $si->canonical_page === $this->perm[$i]) {
                    $perm = $this->perm[$i + 1];
                }
            }
        }
        return $perm;
    }

    /** @param string $name */
    function oldv($name) {
        return $this->si_oldv($this->si($name));
    }
    /** @param string $name
     * @param null|int|string $value */
    function set_oldv($name, $value) {
        $this->explicit_oldv[$name] = $value;
    }
    private function si_oldv(Si $si) {
        if (array_key_exists($si->name, $this->explicit_oldv)) {
            $val = $this->explicit_oldv[$si->name];
        } else if ($si->storage_type & Si::SI_OPT) {
            $val = $this->conf->opt(substr($si->storage(), 4)) ?? $si->default_value;
            if (($si->storage_type & Si::SI_VALUE) && is_bool($val)) {
                $val = (int) $val;
            }
        } else if ($si->storage_type & Si::SI_DATA) {
            $val = $this->conf->setting_data($si->storage()) ?? $si->default_value;
        } else if ($si->storage_type & Si::SI_VALUE) {
            $val = $this->conf->setting($si->storage()) ?? $si->default_value;
        } else {
            error_log("setting $si->name: don't know how to get value");
            $val = $si->default_value;
        }
        if ($val === $si->invalid_value) {
            $val = "";
        }
        if ($si->storage_type & Si::SI_NEGATE) {
            $val = $val ? 0 : 1;
        }
        return $val;
    }

    /** @param string $name
     * @return bool */
    function has_reqv($name) {
        return array_key_exists($name, $this->req);
    }
    /** @param string $name */
    function reqv($name) {
        return $this->req[$name] ?? null;
    }
    /** @return list<Si> */
    private function req_sis(Si $si) {
        $xsis = [];
        foreach ($this->req_has_suffixes[$si->name] ?? [] as $suffix) {
            $xsi = $this->si($si->name . $suffix);
            if ($this->req_has_si($xsi)) {
                $xsis[] = $xsi;
            }
        }
        return $xsis;
    }
    private function req_has_si(Si $si) {
        if (!$si->parser_class
            && $si->type !== "cdate"
            && $si->type !== "checkbox") {
            return array_key_exists($si->name, $this->req);
        } else {
            return !!($this->req["has_{$si->name}"] ?? null);
        }
    }

    /** @param string $name */
    function curv($name) {
        return $this->si_curv($this->si($name));
    }
    private function si_curv(Si $si) {
        if ($this->use_req() && $this->req_has_si($si)) {
            return $this->reqv($si->name);
        } else {
            return $this->si_oldv($si);
        }
    }

    /** @param string $name
     * @return bool */
    function has_savedv($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv);
    }
    /** @param string $name */
    function savedv($name) {
        $si = $this->si($name);
        assert($si->storage_type !== Si::SI_NONE);
        return $this->si_savedv($si->storage(), $si);
    }
    private function si_savedv($s, Si $si) {
        if (array_key_exists($s, $this->savedv)) {
            $v = $this->savedv[$s];
            if ($v !== null) {
                $v = $v[$si->storage_type & Si::SI_DATA ? 1 : 0];
                if ($si->storage_type & Si::SI_NEGATE) {
                    $v = $v ? 0 : 1;
                }
            }
            return $v;
        } else {
            return null;
        }
    }

    /** @param string $name */
    function newv($name) {
        $si = $this->si($name);
        $s = $si->storage();
        if (array_key_exists($s, $this->savedv)) {
            return $this->si_savedv($s, $si);
        } else {
            return $this->si_oldv($si);
        }
    }

    /** @param string $name
     * @return bool */
    function has_interest($name) {
        return !$this->canonical_page || $this->si_has_interest($this->si($name));
    }
    /** @return bool */
    function si_has_interest(Si $si) {
        return !$this->canonical_page
            || !$si->canonical_page
            || $si->canonical_page === $this->canonical_page
            || array_key_exists($si->storage(), $this->savedv);
    }

    /** @param string $name
     * @return void */
    function save($name, $value) {
        $si = $this->si($name);
        if (!$si || $si->storage_type === Si::SI_NONE) {
            error_log("setting $name: no setting or cannot save value");
            return;
        }
        if ($value !== null
            && ($si->storage_type & Si::SI_DATA ? !is_string($value) : !is_int($value))) {
            error_log(caller_landmark() . ": setting $name: invalid value " . var_export($value, true));
            return;
        }
        $s = $si->storage();
        if ($value === $si->default_value
            || ($value === "" && ($si->storage_type & Si::SI_DATA))) {
            $value = null;
        }
        if ($si->storage_type & Si::SI_NEGATE) {
            $value = $value ? 0 : 1;
        }
        if ($si->storage_type & Si::SI_SLICE) {
            if (!isset($this->savedv[$s])) {
                if (!array_key_exists($s, $this->savedv)) {
                    $this->savedv[$s] = [$this->conf->setting($s) ?? 0, $this->conf->setting_data($s)];
                } else {
                    $this->savedv[$s] = [0, null];
                }
            }
            $idx = $si->storage_type & Si::SI_DATA ? 1 : 0;
            $this->savedv[$s][$idx] = $value;
            if ($this->savedv[$s][0] === 0 && $this->savedv[$s][1] === null) {
                $this->savedv[$s] = null;
            }
        } else if ($value === null) {
            $this->savedv[$s] = null;
        } else if ($si->storage_type & Si::SI_DATA) {
            $this->savedv[$s] = [1, $value];
        } else {
            $this->savedv[$s] = [$value, null];
        }
    }
    /** @param string $name
     * @return bool */
    function update($name, $value) {
        if ($value !== $this->oldv($name)) {
            $this->save($name, $value);
            return true;
        } else {
            return false;
        }
    }
    /** @param ?string $name
     * @param callable() $func */
    function register_cleanup_function($name, $func) {
        if ($name !== null) {
            foreach ($this->cleanup_callbacks as $cb) {
                if ($cb[0] === $name)
                    return;
            }
        }
        $this->cleanup_callbacks[] = [$name, $func];
    }

    /** @param string $field
     * @param ?string $classes
     * @return string */
    function feedback_at($field, $classes = null) {
        $t = "";
        $fname = $field instanceof Si ? $field->name : $field;
        foreach ($this->message_list_at($fname) as $mx) {
            $class = $classes ? "feedback $classes" : "feedback";
            $t .= '<div class="' . MessageSet::status_class($mx->status, $class, "is-") . '">' . $mx->message . "</div>";
        }
        return $t;
    }
    /** @param string $field
     * @param ?string $classes */
    function echo_feedback_at($field, $classes = null) {
        echo $this->feedback_at($field, $classes);
    }

    /** @param ?array<string,mixed> $js
     * @return array<string,mixed> */
    private function strip_group_js($js) {
        $njs = [];
        foreach ($js ?? [] as $k => $v) {
            if (strlen($k) < 10
                || (!str_starts_with($k, "group_")
                    && !str_starts_with($k, "hint_")
                    && !str_starts_with($k, "control_")
                    && !str_starts_with($k, "label_")
                    && $k !== "horizontal"))
                $njs[$k] = $v;
        }
        return $njs;
    }
    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function echo_checkbox_only($name, $js = null) {
        $js["id"] = $name;
        $x = $this->curv($name);
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, $js, "checkbox"));
    }
    /** @param string $name
     * @param string $text
     * @param ?array<string,mixed> $js
     * @return void */
    function echo_checkbox($name, $text, $js = null, $hint = null) {
        echo '<div class="', self::add_class("checki", $js["group_class"] ?? null),
            '"><span class="checkc">';
        $this->echo_checkbox_only($name, self::strip_group_js($js));
        echo '</span>', $this->label($name, $text, ["for" => $name, "class" => $js["label_class"] ?? null]);
        $this->echo_feedback_at($name);
        if ($hint) {
            echo '<div class="', self::add_class("settings-ap f-hx", $js["hint_class"] ?? null), '">', $hint, '</div>';
        }
        if (!($js["group_open"] ?? null)) {
            echo "</div>\n";
        }
    }
    /** @param string $name
     * @param array $varr
     * @param ?string $heading
     * @param string|array $rest
     * @return void */
    function echo_radio_table($name, $varr, $heading = null, $rest = []) {
        $x = $this->curv($name);
        if ($x === null || !isset($varr[$x])) {
            $x = 0;
        }
        $rest = is_string($rest) ? ["after" => $rest] : $rest;
        '@phan-var-force array $rest';

        $fold_values = [];
        if (($rest["fold_values"] ?? false) !== false) {
            $fold_values = $rest["fold_values"];
            assert(is_array($fold_values));
        }

        echo '<div id="', $name, '" class="', $this->control_class($name, "form-g settings-radio");
        if (isset($rest["group_class"])) {
            echo ' ', $rest["group_class"];
        }
        if ($fold_values) {
            echo ' has-fold fold', in_array($x, $fold_values) ? "o" : "c",
                '" data-fold-values="', join(" ", $fold_values);
        }
        echo '">';
        if ($heading) {
            echo '<div class="settings-itemheading">', $heading, '</div>';
        }
        foreach ($varr as $k => $item) {
            if (is_string($item)) {
                $item = ["label" => $item];
            }
            $label = $item["label"];
            $hint = $item["hint"] ?? "";
            unset($item["label"], $item["hint"]);
            $item["id"] = "{$name}_{$k}";
            if (!isset($item["class"])) {
                if (isset($rest["item_class"])) {
                    $item["class"] = $rest["item_class"];
                } else if ($fold_values) {
                    $item["class"] = "uich js-foldup";
                }
            }

            $label1 = "<label>";
            $label2 = "</label>";
            if (strpos($label, "<label") !== false) {
                $label1 = $label2 = "";
            }

            echo '<div class="settings-radioitem checki">',
                $label1, '<span class="checkc">',
                Ht::radio($name, $k, $k == $x, $this->sjs($name, $item, "radio")),
                '</span>', $label, $label2, $hint, '</div>';
        }
        $this->echo_feedback_at($name);
        if (isset($rest["after"])) {
            echo $rest["after"];
        }
        echo "</div>\n";
    }
    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return string */
    function entry($name, $js = null) {
        $si = $this->si($name);
        $v = $this->si_curv($si);
        $t = "";
        if (!$this->use_req() || !$this->si_has_interest($si)) {
            $v = $this->si_unparse_value($v, $si);
        }
        $js = $js ?? [];
        if ($si->size && !isset($js["size"])) {
            $js["size"] = $si->size;
        }
        if ($si->placeholder !== null && !isset($js["placeholder"])) {
            $js["placeholder"] = $si->placeholder;
        }
        if ($si->autogrow) {
            $js["class"] = ltrim(($js["class"] ?? "") . " need-autogrow");
        }
        if ($si->parser_class) {
            $t = Ht::hidden("has_$name", 1);
        }
        return Ht::entry($name, $v, $this->sjs($si, $js, "text")) . $t;
    }
    /** @param string $name
     * @return void */
    function echo_entry($name) {
        echo $this->entry($name);
    }
    function echo_control_group($name, $description, $control,
                                $js = null, $hint = null) {
        $si = $this->si($name);
        if (($horizontal = $js["horizontal"] ?? null) !== null) {
            unset($js["horizontal"]);
        }
        $klass = $horizontal ? "entryi" : "f-i";
        if ($description === null) {
            $description = $si->title_html($this);
        }

        echo '<div class="', $this->control_class($name, $klass), '">',
            $this->label($name, $description, ["class" => $js["label_class"] ?? null, "no_control_class" => true]);
        if ($horizontal) {
            echo '<div class="entry">';
        }
        $this->echo_feedback_at($name);
        echo $control, ($js["control_after"] ?? "");
        $thint = $this->type_hint($si->type);
        if ($hint || $thint) {
            echo '<div class="f-h">';
            if ($hint && $thint) {
                echo '<div>', $hint, '</div><div>', $thint, '</div>';
            } else if ($hint || $thint) {
                echo $hint ? : $thint;
            }
            echo '</div>';
        }
        if ($horizontal) {
            echo "</div>";
        }
        if (!($js["group_open"] ?? null)) {
            echo "</div>\n";
        }
    }
    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function echo_entry_group($name, $description, $js = null, $hint = null) {
        $this->echo_control_group($name, $description,
            $this->entry($name, self::strip_group_js($js)),
            $js, $hint);
    }
    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return string */
    function select($name, $values, $js = null) {
        $si = $this->si($name);
        $v = $this->si_curv($si);
        $t = "";
        if ($si->parser_class) {
            $t = Ht::hidden("has_$name", 1);
        }
        return Ht::select($name, $values, $v !== null ? $v : 0, $this->sjs($si, $js, "select")) . $t;
    }
    function echo_select_group($name, $values, $description, $js = null, $hint = null) {
        $this->echo_control_group($name, $description,
            $this->select($name, $values, self::strip_group_js($js)),
            $js, $hint);
    }
    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return string */
    function textarea($name, $js = null) {
        $si = $this->si($name);
        $v = $this->si_curv($si);
        $t = "";
        $rows = 10;
        if ($si->size) {
            $rows = $si->size;
        }
        $js = $js ?? [];
        if ($si->placeholder !== null) {
            $js["placeholder"] = $si->placeholder;
        }
        if ($si->autogrow || $si->autogrow === null) {
            $js["class"] = ltrim(($js["class"] ?? "") . " need-autogrow");
        }
        if ($si->parser_class) {
            $t = Ht::hidden("has_$name", 1);
        }
        if (!isset($js["rows"])) {
            $js["rows"] = $rows;
        }
        if (!isset($js["cols"])) {
            $js["cols"] = 80;
        }
        return Ht::textarea($name, $v, $this->sjs($si, $js, "textarea")) . $t;
    }
    private function echo_message_base($name, $description, $hint, $xclass) {
        $si = $this->si($name);
        if (str_starts_with($si->storage(), "msg.")) {
            $si->default_value = $this->si_message_default($si);
        }
        $current = $this->curv($name);
        $description = '<a class="ui qq js-foldup" href="">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="f-i has-fold fold', ($current == $si->default_value ? "c" : "o"), '">',
            '<div class="f-c', $xclass, ' ui js-foldup">',
            $this->label($name, $description),
            ' <span class="n fx">(HTML allowed)</span></div>',
            $this->feedback_at($name),
            $this->textarea($name, ["class" => "fx"]),
            $hint, "</div>\n";
    }
    function echo_message($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "");
    }
    function echo_message_minor($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, " n");
    }
    function echo_message_horizontal($name, $description, $hint = "") {
        $si = $this->si($name);
        if (str_starts_with($si->storage(), "msg.")) {
            $si->default_value = $this->si_message_default($si);
        }
        $current = $this->curv($name);
        if ($current !== $si->default_value) {
            echo '<div class="entryi">',
                $this->label($name, $description), '<div>';
            $close = "";
        } else {
            $description = '<a class="ui qq js-foldup href="">'
                . expander(null, 0) . $description . '</a>';
            echo '<div class="entryi has-fold foldc">',
                $this->label($name, $description), '<div>',
                '<div class="dim ui js-foldup fn">default</div>',
                '<div class="fx">';
            $close = "</div>";
        }
        echo '<div class="f-c n">(HTML allowed)</div>',
            $this->textarea($name),
            $hint, $close, "</div></div>";
    }

    private function si_unparse_value($v, Si $si) {
        if ($si->type === "cdate" || $si->type === "checkbox") {
            return $v ? "1" : "";
        } else if ($si->is_date()) {
            return $this->si_unparse_date_value($v, $si);
        } else if ($si->type === "grace") {
            return $this->si_unparse_grace_value($v, $si);
        } else {
            return $v;
        }
    }
    private function si_unparse_date_value($v, Si $si) {
        if ($si->date_backup
            && $this->curv($si->date_backup) == $v) {
            return "";
        } else if ($si->placeholder !== "N/A"
                   && $si->placeholder !== "none"
                   && $v === 0) {
            return "none";
        } else if ($v <= 0) {
            return "";
        } else if ($v == 1) {
            return "now";
        } else {
            return $this->conf->parseableTime($v, true);
        }
    }
    private function si_unparse_grace_value($v, Si $si) {
        if ($v === null || $v <= 0 || !is_numeric($v)) {
            return "none";
        } else if ($v % 3600 == 0) {
            return ($v / 3600) . " hr";
        } else if ($v % 60 == 0) {
            return ($v / 60) . " min";
        } else {
            return sprintf("%d:%02d", intval($v / 60), $v % 60);
        }
    }
    function check_date_before($name0, $name1, $force_name0) {
        if (($d1 = $this->newv($name1))) {
            $d0 = $this->newv($name0);
            if (!$d0) {
                if ($force_name0)
                    $this->save($name0, $d1);
            } else if ($d0 > $d1) {
                $si1 = $this->si($name1);
                $this->error_at($this->si($name0), "Must come before " . $this->setting_link($si1->title_html($this), $si1) . ".");
                $this->error_at($si1);
                return false;
            }
        }
        return true;
    }

    function type_hint($type) {
        if (str_ends_with($type, "date") && !isset($this->hint_status["date"])) {
            $this->hint_status["date"] = true;
            return "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2019-10-31 UTC-1100”, “Dec 31 AoE” <a href=\"http://php.net/manual/en/datetime.formats.php\">(more examples)</a>";
        } else if ($type === "grace" && !isset($this->hint_status["grace"])) {
            $this->hint_status["grace"] = true;
            return "Example: “15 min”";
        } else {
            return false;
        }
    }

    function expand_mail_template($name, $default) {
        if (!$this->null_mailer) {
            $this->null_mailer = new HotCRPMailer($this->conf, null, ["width" => false]);
        }
        return $this->null_mailer->expand_template($name, $default);
    }

    /** @param Si $si */
    private function si_message_default($si) {
        assert(str_starts_with($si->storage(), "msg."));
        $ctxarg = null;
        if (($ctxname = $si->message_context_setting)) {
            $ctxarg = $this->curv($ctxname[0] === "+" ? substr($ctxname, 1) : $ctxname);
        }
        $t = $this->conf->ims()->default_itext(substr($si->storage(), 4), null, $ctxarg);
        return $t !== "" || !$si->parent ? $t : $this->si_message_default($si->parent);
    }

    /** @param string|Si $si
     * @return string */
    function setting_link($html, $si, $js = null) {
        if (!($si instanceof Si)) {
            $si = $this->si($si);
        }
        return Ht::link($html, $si->sv_hoturl($this), $js);
    }


    function parse_value(Si $si) {
        $v = $this->reqv($si->name);
        if ($v === null) {
            if (in_array($si->type, ["cdate", "checkbox"])) {
                return 0;
            } else {
                return null;
            }
        }

        $v = trim($v);
        if (($si->placeholder !== null && $si->placeholder === $v)
            || ($si->invalid_value && $si->invalid_value === $v)) {
            $v = "";
        }

        if ($si->type === "checkbox") {
            return $v != "" ? 1 : 0;
        } else if ($si->type === "cdate") {
            if ($v != "") {
                $v = $this->si_oldv($si);
                return $v > 0 ? $v : Conf::$now;
            } else {
                return 0;
            }
        } else if ($si->type === "date" || $si->type === "ndate") {
            if ((string) $v === ""
                || $v === "0"
                || !strcasecmp($v, "N/A")
                || !strcasecmp($v, "same as PC")
                || ($si->type !== "ndate" && !strcasecmp($v, "none"))) {
                return -1;
            } else if (!strcasecmp($v, "none")) {
                return 0;
            } else if (($v = $this->conf->parse_time($v)) !== false) {
                return $v;
            }
            $err = "Should be a date.";
        } else if ($si->type === "grace") {
            if (($v = SettingParser::parse_interval($v)) !== false) {
                return intval($v);
            }
            $err = "Should be a grace period.";
        } else if ($si->type === "int" || $si->type === "zint") {
            if (preg_match("/\\A[-+]?[0-9]+\\z/", $v)) {
                return intval($v);
            } else if ($v == "" && $si->placeholder !== null) {
                return 0;
            }
            $err = "Should be a number.";
        } else if ($si->type === "string") {
            // Avoid storing the default message in the database
            if (substr($si->name, 0, 9) == "mailbody_") {
                $t = $this->expand_mail_template(substr($si->name, 9), true);
                $v = cleannl($v);
                if ($t["body"] === $v) {
                    return "";
                }
            }
            return $v;
        } else if ($si->type === "simplestring") {
            return simplify_whitespace($v);
        } else if ($si->type === "tag"
                   || $si->type === "tagbase") {
            $tagger = new Tagger($this->user);
            $v = trim($v);
            if ($v === "" && $si->optional) {
                return $v;
            }
            $v = $tagger->check($v, $si->type === "tagbase" ? Tagger::NOVALUE : 0);
            if ($v) {
                return $v;
            }
            $err = $tagger->error_html();
        } else if ($si->type === "emailheader") {
            $mt = new MimeText;
            $v = $mt->encode_email_header("", $v);
            if ($v !== false) {
                return ($v == "" ? "" : MimeText::decode_header($v));
            }
            $err = "Malformed destination list: " . $mt->unparse_error();
        } else if ($si->type === "emailstring") {
            $v = trim($v);
            if ($v === "" && $si->optional) {
                return "";
            } else if (validate_email($v) || $v === $this->oldv($si->name)) {
                return $v;
            }
            $err = "Should be an email address.";
        } else if ($si->type === "urlstring") {
            $v = trim($v);
            if (($v === "" && $si->optional)
                || preg_match(',\A(?:https?|ftp)://\S+\z,', $v)) {
                return $v;
            }
            $err = "Should be a URL.";
        } else if ($si->type === "htmlstring") {
            if (($v = CleanHTML::basic_clean($v, $err)) !== false) {
                if (str_starts_with($si->storage(), "msg.")
                    && $v === $this->si_message_default($si))
                    return "";
                return $v;
            }
            /* $err set by CleanHTML::basic_clean */
        } else if ($si->type === "radio") {
            foreach ($si->values as $allowedv) {
                if ((string) $allowedv === $v)
                    return $allowedv;
            }
            $err = "Unexpected value.";
        } else {
            return $v;
        }

        $this->error_at($si, $err);
        return null;
    }

    private function account(Si $si1) {
        foreach ($this->req_sis($si1) as $si) {
            if (!$si->active_value()
                || !$this->si_editable($si)) {
                /* ignore changes to disabled/internal settings */;
            } else if ($si->parser_class) {
                if ($this->si_parser($si)->parse($this, $si)) {
                    $this->saved_si[] = $si;
                }
            } else if ($si->storage_type !== Si::SI_NONE) {
                $v = $this->parse_value($si);
                if ($v === null || $v === false) {
                    return;
                }
                if (is_int($v)
                    && $v <= 0
                    && $si->type !== "radio"
                    && $si->type !== "zint") {
                    $v = null;
                }
                $this->save($si->name, $v);
                if ($si->validator_class) {
                    $this->validate_si[] = $si;
                }
                if ($si->ifnonempty) {
                    $this->save($si->ifnonempty, $v === null || $v === "" ? null : 1);
                }
            }
        }
    }

    /** @param string ...$tables */
    function request_read_lock(...$tables) {
        foreach ($tables as $t) {
            $this->_table_lock[$t] = max($this->_table_lock[$t] ?? 0, 1);
        }
    }

    /** @param string ...$tables */
    function request_write_lock(...$tables) {
        foreach ($tables as $t) {
            $this->_table_lock[$t] = max($this->_table_lock[$t] ?? 0, 2);
        }
    }

    function execute() {
        // parse and validate settings
        foreach (Si::si_map($this->conf) as $si) {
            $this->account($si);
        }
        foreach ($this->validate_si as $si) {
            if ($this->si_parser($si)->validate($this, $si)) {
                $this->saved_si[] = $si;
            }
        }
        $this->request_write_lock(...array_keys($this->need_lock));
        $this->request_read_lock("ContactInfo");

        // make settings
        $this->diffs = [];
        if (!$this->has_error()
            && (!empty($this->savedv) || !empty($this->saved_si))) {
            $tables = "Settings write";
            foreach ($this->_table_lock as $t => $need) {
                $tables .= ", $t " . ($need < 2 ? "read" : "write");
            }
            $this->conf->qe_raw("lock tables $tables");

            // load db settings, pre-crosscheck
            $dbsettings = array();
            $result = $this->conf->qe("select name, value, data from Settings");
            while (($row = $result->fetch_row())) {
                $dbsettings[$row[0]] = $row;
            }
            Dbl::free($result);

            // apply settings
            foreach ($this->saved_si as $si) {
                $this->si_parser($si)->save($this, $si);
            }

            $dv = $av = array();
            foreach ($this->savedv as $n => $v) {
                if (substr($n, 0, 4) === "opt.") {
                    $okey = substr($n, 4);
                    if (array_key_exists($okey, $this->conf->opt_override)) {
                        $oldv = $this->conf->opt_override[$okey];
                    } else {
                        $oldv = $this->conf->opt($okey);
                    }
                    $vi = Si::$option_is_value[$okey] ? 0 : 1;
                    $basev = $vi ? "" : 0;
                    $newv = $v === null ? $basev : $v[$vi];
                    if ($oldv === $newv) {
                        $v = null; // delete override value in database
                    } else if ($v === null && $oldv !== $basev && $oldv !== null) {
                        $v = $vi ? [0, ""] : [0, null];
                    }
                }
                if ($v === null
                    ? !isset($dbsettings[$n])
                    : isset($dbsettings[$n]) && (int) $dbsettings[$n][1] === $v[0] && $dbsettings[$n][2] === $v[1]) {
                    continue;
                }
                $this->diffs[$n] = true;
                if ($v !== null) {
                    $av[] = [$n, $v[0], $v[1]];
                } else {
                    $dv[] = $n;
                }
            }
            if (!empty($dv)) {
                $this->conf->qe("delete from Settings where name?a", $dv);
                //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query("delete from Settings where name?a", $dv)));
            }
            if (!empty($av)) {
                $this->conf->qe("insert into Settings (name, value, data) values ?v on duplicate key update value=values(value), data=values(data)", $av);
                //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query("insert into Settings (name, value, data) values ?v on duplicate key update value=values(value), data=values(data)", $av)));
            }

            $this->conf->qe_raw("unlock tables");
            if (!empty($this->diffs)) {
                $this->user->log_activity("Settings edited: " . join(", ", array_keys($this->diffs)));
            }

            // clean up
            $this->conf->load_settings();
            foreach ($this->cleanup_callbacks as $cba) {
                $cb = $cba[1];
                $cb();
            }
            if (!empty($this->invalidate_caches)) {
                $this->conf->invalidate_caches($this->invalidate_caches);
            }
        }
        return !$this->has_error();
    }


    function unparse_json_value(Si $si) {
        $v = $this->si_oldv($si);
        if ($si->type === "checkbox" || $si->type === "cdate") {
            return !!$v;
        } else if ($si->type === "date" || $si->type === "ndate") {
            if ($v > 0) {
                return $this->conf->parseableTime($v, true);
            } else {
                return false;
            }
        } else if ($si->type === "grace") {
            if ($v > 0) {
                return $this->si_unparse_grace_value($v, $si);
            } else {
                return false;
            }
        } else if ($si->type === "int") {
            return $v > 0 ? (int) $v : false;
        } else if ($si->type === "zint") {
            return (int) $v;
        } else if ($si->type === "string"
                   || $si->type === "simplestring"
                   || $si->type === "tag"
                   || $si->type === "tagbase"
                   || $si->type === "emailheader"
                   || $si->type === "emailstring"
                   || $si->type === "urlstring"
                   || $si->type === "htmlstring") {
            return (string) $v !== "" ? $v : false;
        } else if ($si->type === "radio") {
            $pos = array_search($v, $si->values);
            if ($pos !== false && $si->json_values && isset($si->json_values[$pos])) {
                return $si->json_values[$pos];
            } else {
                return $v;
            }
        } else {
            return $v;
        }
    }

    function unparse_json() {
        assert(!$this->use_req());
        $j = (object) [];
        foreach (Si::si_map($this->conf) as $si) {
            if ($this->si_has_interest($si)
                && $si->active_value()
                && $si->json_name) {
                if ($si->parser_class) {
                    $this->si_parser($si)->unparse_json($this, $si, $j);
                } else {
                    $v = $this->unparse_json_value($si);
                    $j->{$si->json_name} = $v;
                }
            }
        }
        return $j;
    }

    function parse_json_value(Si $si, $v) {
        if ($v === null) {
            return;
        }
        if (in_array($si->type, ["cdate", "checkbox"])
            && is_bool($v)) {
            $this->set_req("has_{$si->name}", "1");
            if ($v) {
                $this->set_req($si->name, "1");
            }
            return;
        } else if ($si->type === "date"
                   || $si->type === "cdate"
                   || $si->type === "ndate"
                   || $si->type === "grace") {
            if (is_string($v) || $v === false) {
                $this->set_req($si->name, $v === false ? "none" : $v);
                return;
            }
        } else if ($si->type === "int"
                   || $si->type === "zint") {
            if (is_int($v) || ($si->type === "int" && $v === false)) {
                $this->set_req($si->name, (string) $v);
                return;
            }
        } else if ($si->type === "string"
                   || $si->type === "simplestring"
                   || $si->type === "tag"
                   || $si->type === "tagbase"
                   || $si->type === "emailheader"
                   || $si->type === "emailstring"
                   || $si->type === "urlstring"
                   || $si->type === "htmlstring") {
            if (is_string($v) || $v === false) {
                $this->set_req($si->name, (string) $v);
                return;
            }
        } else if ($si->type === "radio") {
            $jvalues = $si->json_values ? : $si->values;
            $pos = array_search($v, $jvalues);
            if ($pos === false && ($v === false || $v === true)) {
                $pos = array_search($v ? "yes" : "no", $jvalues);
            } else if ($pos === false && ($v === "yes" || $v === "no")) {
                $pos = array_search($v === "yes" ? true : false, $jvalues);
            }
            if ($pos !== false) {
                $this->set_req($si->name, (string) $si->values[$pos]);
                return;
            }
        }

        $this->error_at($si, "Invalid value.");
    }

    /** @param string $siname */
    function mark_diff($siname)  {
        $this->diffs[$siname] = true;
    }

    /** @param string $siname
     * @return bool */
    function has_diff($siname) {
        return $this->diffs[$siname] ?? false;
    }

    /** @param associative-array<string,true> $caches */
    function mark_invalidate_caches($caches) {
        foreach ($caches as $c => $t) {
            $this->invalidate_caches[$c] = true;
        }
    }

    /** @return list<string> */
    function updated_fields() {
        return array_keys($this->diffs);
    }
}
