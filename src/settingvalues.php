<?php
// settingvalues.php -- HotCRP conference settings management helper classes
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// setting information
class Si {
    public $name;
    public $title;
    public $group;
    public $type;
    public $internal;
    public $extensible = 0;
    public $storage_type;
    public $storage = null;
    public $optional = false;
    public $values;
    public $size;
    public $placeholder;
    public $parser;
    public $novalue = false;
    public $disabled = false;
    public $invalid_value = null;
    public $default_value = null;
    public $autogrow = null;
    public $ifnonempty;
    public $message_default;
    public $date_backup;

    static public $option_is_value = [];

    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;

    const X_YES = 1;
    const X_WORD = 2;

    static public $all = [];
    static private $type_storage = [
        "emailheader" => self::SI_DATA, "emailstring" => self::SI_DATA,
        "htmlstring" => self::SI_DATA, "simplestring" => self::SI_DATA,
        "string" => self::SI_DATA, "tag" => self::SI_DATA,
        "tagbase" => self::SI_DATA, "taglist" => self::SI_DATA,
        "urlstring" => self::SI_DATA
    ];

    private function store($key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey) && call_user_func($typecheck, $j->$jkey))
            $this->$key = $j->$jkey;
        else if (isset($j->$jkey))
            trigger_error("setting {$j->name}.$jkey format error");
    }

    function __construct($j) {
        assert(!preg_match('/_(?:\$|n|m?\d+)\z/', $j->name));
        $this->name = $this->title = $j->name;
        foreach (["title", "type", "storage", "parser", "ifnonempty", "message_default", "placeholder", "invalid_value", "date_backup"] as $k)
            $this->store($k, $j, $k, "is_string");
        foreach (["internal", "optional", "novalue", "disabled", "autogrow"] as $k)
            $this->store($k, $j, $k, "is_bool");
        $this->store("size", $j, "size", "is_int");
        $this->store("values", $j, "values", "is_array");
        if (isset($j->default_value) && (is_int($j->default_value) || is_string($j->default_value)))
            $this->default_value = $j->default_value;
        if (isset($j->extensible) && $j->extensible === true)
            $this->extensible = self::X_YES;
        else if (isset($j->extensible) && $j->extensible === "word")
            $this->extensible = self::X_WORD;
        else if (isset($j->extensible) && $j->extensible !== false)
            trigger_error("setting {$j->name}.extensible format error");
        if (isset($j->group)) {
            if (is_string($j->group))
                $this->group = $j->group;
            else if (is_array($j->group)) {
                $this->group = [];
                foreach ($j->group as $g)
                    if (is_string($g))
                        $this->group[] = $g;
                    else
                        trigger_error("setting {$j->name}.group format error");
            }
        }

        if (!$this->type && $this->parser)
            $this->type = "special";
        $s = $this->storage ? : $this->name;
        $pfx = substr($s, 0, 4);
        if ($pfx === "opt.")
            $this->storage_type = self::SI_DATA | self::SI_OPT;
        else if ($pfx === "ova.") {
            $this->storage_type = self::SI_VALUE | self::SI_OPT;
            $this->storage = "opt." . substr($s, 4);
        } else if ($pfx === "val.") {
            $this->storage_type = self::SI_VALUE | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if ($pfx === "dat.") {
            $this->storage_type = self::SI_DATA | self::SI_SLICE;
            $this->storage = substr($s, 4);
        } else if (isset(self::$type_storage[$this->type]))
            $this->storage_type = self::$type_storage[$this->type];
        else
            $this->storage_type = self::SI_VALUE;
        if ($this->storage_type & self::SI_OPT) {
            $is_value = !!($this->storage_type & self::SI_VALUE);
            $oname = substr($this->storage ? : $this->name, 4);
            if (!isset(self::$option_is_value[$oname]))
                self::$option_is_value[$oname] = $is_value;
            if (self::$option_is_value[$oname] != $is_value)
                error_log("$oname: conflicting option_is_value");
        }

        // defaults for size, placeholder
        if (str_ends_with($this->type, "date")) {
            if ($this->size === null)
                $this->size = 30;
            if ($this->placeholder === null)
                $this->placeholder = "N/A";
        } else if ($this->type == "grace") {
            if ($this->size === null)
                $this->size = 15;
            if ($this->placeholder === null)
                $this->placeholder = "none";
        }
    }

    function is_date() {
        return str_ends_with($this->type, "date");
    }

    function storage() {
        return $this->storage ? : $this->name;
    }

    function is_interesting(SettingValues $sv) {
        if (!$this->group) {
            error_log("$this->name: missing group");
            return false;
        }
        $groups = $this->group;
        foreach (is_string($groups) ? [$groups] : $groups as $g) {
            if ($sv->group_is_interesting($g))
                return true;
        }
        return false;
    }

    static function get($name, $k = null) {
        if (!isset(self::$all[$name])
            && preg_match('/\A(.*)(_(?:[^_\s]+))\z/', $name, $m)
            && isset(self::$all[$m[1]])) {
            $si = clone self::$all[$m[1]];
            if (!$si->extensible
                || ($si->extensible === self::X_YES
                    && !preg_match('/\A_(?:\$|n|m?\d+)\z/', $m[2])))
                error_log("$name: cloning non-extensible setting $si->name");
            $si->name = $name;
            if ($si->storage)
                $si->storage .= $m[2];
            if ($si->extensible === self::X_WORD)
                $si->title .= " (" . htmlspecialchars(substr($m[2], 1)) . ")";
            self::$all[$name] = $si;
        }
        if (!isset(self::$all[$name]))
            return null;
        $si = self::$all[$name];
        if ($k)
            return $si->$k;
        return $si;
    }


    static private function read($info, $text, $fname) {
        $j = json_decode($text, true);
        if (is_array($j))
            $info = array_replace_recursive($info, $j);
        else if (json_last_error() !== JSON_ERROR_NONE) {
            Json::decode($text); // our JSON decoder provides error positions
            trigger_error("$fname: Invalid JSON, " . Json::last_error_msg());
        }
        return $info;
    }

    static function _add_json($j) {
        if (is_object($j) && isset($j->name) && is_string($j->name)) {
            self::$all[] = $j;
            return true;
        } else
            return false;
    }

    static function initialize() {
        global $Conf;
        self::$all = [];
        expand_json_includes_callback(["etc/settings.json"], "Si::_add_json");
        if (($olist = $Conf->opt("settingSpecs")))
            expand_json_includes_callback($olist, "Si::_add_json");
        usort(self::$all, "Conf::xt_priority_compare");

        $all = [];
        $nall = count(self::$all);
        for ($i = 0; $i < $nall; ++$i) {
            $j = self::$all[$i];
            if ($Conf->xt_allowed($j) && !isset($all[$j->name])) {
                while (isset($j->merge) && $j->merge && $i + 1 < $nall
                       && $j->name === self::$all[$i + 1]->name) {
                    unset($j->merge);
                    $j = object_replace_recursive(self::$all[$i + 1], $j);
                    ++$i;
                }
                Conf::xt_resolve_require($j);
                $class = get_s($j, "factory_class", "Si");
                $all[$j->name] = new $class($j);
            }
        }
        self::$all = $all;
    }
}

class SettingParser {
    function parse(SettingValues $sv, Si $si) {
        return false;
    }
    function save(SettingValues $sv, Si $si) {
    }

    static function parse_grace($v) {
        $t = 0;
        $v = trim($v);
        if ($v == "" || strtoupper($v) == "N/A" || strtoupper($v) == "NONE" || $v == "0")
            return -1;
        if (ctype_digit($v))
            return $v * 60;
        if (preg_match('/^\s*([\d]+):([\d.]+)\s*$/', $v, $m))
            return $m[1] * 60 + $m[2];
        if (preg_match('/^\s*([\d.]+)\s*d(ays?)?(?![a-z])/i', $v, $m)) {
            $t += $m[1] * 3600 * 24;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/^\s*([\d.]+)\s*h(rs?|ours?)?(?![a-z])/i', $v, $m)) {
            $t += $m[1] * 3600;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/^\s*([\d.]+)\s*m(in(ute)?s?)?(?![a-z])/i', $v, $m)) {
            $t += $m[1] * 60;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/^\s*([\d.]+)\s*s(ec(ond)?s?)?(?![a-z])/i', $v, $m)) {
            $t += $m[1];
            $v = substr($v, strlen($m[0]));
        }
        if (trim($v) == "")
            return $t;
        else
            return null;
    }

    static private $null_mailer;
    static function expand_mail_template($name, $default) {
        if (!self::$null_mailer)
            self::$null_mailer = new HotCRPMailer(null, null, array("width" => false));
        return self::$null_mailer->expand_template($name, $default);
    }
}

class SettingValues extends MessageSet {
    public $conf;
    public $user;
    public $interesting_groups = [];

    private $parsers = [];
    private $saved_si = [];
    private $cleanup_callbacks = [];
    public $need_lock = [];
    public $changes = [];

    public $req = array();
    public $req_files = array();
    public $savedv = array();
    public $explicit_oldv = array();
    private $hint_status = array();
    private $has_req = array();
    private $near_msgs = null;

    private $_gxt = null;

    function __construct(Contact $user) {
        parent::__construct();
        $this->conf = $user->conf;
        $this->user = $user;
        $this->near_msgs = new MessageSet;
        // maybe set $Opt["contactName"] and $Opt["contactEmail"]
        $this->conf->site_contact();
    }
    static function make_request(Contact $user, $post, $files = []) {
        $sv = new SettingValues($user);
        $got = [];
        foreach ($post as $k => $v) {
            $sv->req[$k] = $v;
            if (preg_match('/\A(?:has_)?(\S+?)(|_n|_m?\d+)\z/', $k, $m)) {
                if (!isset($sv->has_req[$m[1]]))
                    $sv->has_req[$m[1]] = [];
                if (!isset($got[$m[1] . $m[2]])) {
                    $sv->has_req[$m[1]][] = $m[2];
                    $got[$m[1] . $m[2]] = true;
                }
            }
        }
        foreach ($files as $f => $finfo)
            if (($e = $finfo["error"]) == UPLOAD_ERR_OK) {
                if (is_uploaded_file($finfo["tmp_name"]))
                    $sv->req_files[$f] = $finfo;
            }
        return $sv;
    }
    function session_highlight() {
        foreach ($this->conf->session("settings_highlight", []) as $f => $v)
            $this->msg($f, null, $v);
        $this->conf->save_session("settings_highlight", null);
    }


    private function gxt() {
        if ($this->_gxt === null)
            $this->_gxt = new GroupedExtensions($this->user, ["etc/settinggroups.json"], $this->conf->opt("settingGroups"));
        return $this->_gxt;
    }
    function canonical_group($g) {
        return $this->gxt()->canonical_group(strtolower($g));
    }
    function is_titled_group($g) {
        $gj = $this->gxt()->get($g);
        return $gj && $gj->name == $gj->group && isset($gj->title);
    }
    function group_titles() {
        return array_map(function ($gj) { return $gj->title; }, $this->gxt()->groups());
    }
    function mark_interesting_group($g) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            $this->interesting_groups[$gj->name] = true;
            foreach ($gj->synonym as $syn)
                $this->interesting_groups[$syn] = true;
        }
    }
    function crosscheck() {
        foreach ($this->gxt()->all() as $gj) {
            if (isset($gj->crosschecker)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->crosschecker, $this, $gj);
            }
        }
    }
    function render_group($g) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            if (isset($gj->renderer)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->renderer, $this, $gj);
            }
        }
    }


    function use_req() {
        return $this->has_error();
    }
    static private function check_error_field($field, &$html) {
        if ($field instanceof Si) {
            if ($field->title && $html !== false)
                $html = htmlspecialchars($field->title) . ": " . $html;
            return $field->name;
        } else
            return $field;
    }
    function error_at($field, $html = false) {
        $fname = self::check_error_field($field, $html);
        parent::error_at($fname, $html);
    }
    function warning_at($field, $html = false) {
        $fname = self::check_error_field($field, $html);
        parent::warning_at($fname, $html);
    }
    function error_near($field, $html)  {
        $this->near_msgs->error_at($field, $html);
    }
    function warning_near($field, $html)  {
        $this->near_msgs->warning_at($field, $html);
    }
    function info_near($field, $html)  {
        $this->near_msgs->info_at($field, $html);
    }
    function report($is_update = false) {
        $msgs = array();
        if ($is_update && $this->has_error())
            $msgs[] = "Your changes were not saved. Please fix these errors and try again.";
        foreach ($this->messages(true) as $mx)
            $msgs[] = ($mx[2] == MessageSet::WARNING ? "Warning: " : "") . $mx[1];
        if (!empty($msgs) && $this->has_error())
            Conf::msg_error($msgs, true);
        else if (!empty($msgs))
            Conf::msg_warning($msgs, true);
    }
    function parser(Si $si) {
        if (($class = $si->parser)) {
            if (!isset($this->parsers[$class]))
                $this->parsers[$class] = new $class($this);
            return $this->parsers[$class];
        } else
            return null;
    }
    function group_is_interesting($g) {
        return isset($this->interesting_groups[$g]);
    }

    function label($name, $html, $label_id = null, $label_js = null) {
        $name1 = is_array($name) ? $name[0] : $name;
        foreach (is_array($name) ? $name : array($name) as $n)
            if ($this->has_problem_at($n)) {
                $html = '<span class="setting_error">' . $html . '</span>';
                break;
            }
        if ($label_id !== false) {
            $label_id = $label_id ? : $name1;
            $post = "";
            if (($pos = strpos($html, "<input")) !== false)
                list($html, $post) = [substr($html, 0, $pos), substr($html, $pos)];
            $html = Ht::label($html, $label_id, $label_js) . $post;
        }
        return $html;
    }
    function sjs($name, $extra = array()) {
        $x = ["id" => $name];
        if (Si::get($name, "disabled"))
            $x["disabled"] = true;
        foreach ($extra as $k => $v)
            $x[$k] = $v;
        if ($this->has_problem_at($name))
            $x["class"] = trim("setting_error " . (get($x, "class") ? : ""));
        return $x;
    }

    function si($name) {
        $si = Si::get($name);
        if (!$si)
            error_log(caller_landmark(2) . ": setting $name: missing information");
        return $si;
    }
    private function req_has($xname, $suffix) {
        $x = get($this->req, "has_$xname$suffix");
        return $x && $x !== "false";
    }
    function req_si(Si $si) {
        $xname = str_replace(".", "_", $si->name);
        $xsis = [];
        foreach (get($this->has_req, $xname, []) as $suffix) {
            $xsi = $this->si($si->name . $suffix);
            if ($xsi->parser)
                $has_value = $this->req_has($xname, $suffix);
            else
                $has_value = isset($this->req["$xname$suffix"])
                    || (($xsi->type === "cdate" || $xsi->type === "checkbox")
                        && $this->req_has($xname, $suffix));
            if ($has_value)
                $xsis[] = $xsi;
        }
        return $xsis;
    }

    function curv($name, $default_value = null) {
        return $this->si_curv($name, $this->si($name), $default_value);
    }
    function oldv($name, $default_value = null) {
        return $this->si_oldv($this->si($name), $default_value);
    }
    function reqv($name, $default_value = null) {
        $name = str_replace(".", "_", $name);
        return get($this->req, $name, $default_value);
    }
    function has_savedv($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv);
    }
    function has_interest($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv)
            || $si->is_interesting($this);
    }
    function savedv($name, $default_value = null) {
        $si = $this->si($name);
        return $this->si_savedv($si->storage(), $si, $default_value);
    }
    function newv($name, $default_value = null) {
        $si = $this->si($name);
        $s = $si->storage();
        if (array_key_exists($s, $this->savedv))
            return $this->si_savedv($s, $si, $default_value);
        else
            return $this->si_oldv($si, $default_value);
    }

    function set_oldv($name, $value) {
        $this->explicit_oldv[$name] = $value;
    }
    function save($name, $value) {
        $si = $this->si($name);
        if (!$si)
            return;
        if ($value !== null
            && !($si->storage_type & Si::SI_DATA ? is_string($value) : is_int($value))) {
            error_log(caller_landmark() . ": setting $name: invalid value " . var_export($value, true));
            return;
        }
        $s = $si->storage();
        if ($si->storage_type & Si::SI_SLICE) {
            if (!isset($this->savedv[$s]))
                $this->savedv[$s] = [$this->conf->setting($s, 0), $this->conf->setting_data($s, null)];
            $idx = $si->storage_type & Si::SI_DATA ? 1 : 0;
            $this->savedv[$s][$idx] = $value;
            if ($this->savedv[$s][0] === 0 && $this->savedv[$s][1] === null)
                $this->savedv[$s] = null;
        } else if ($si->storage_type & Si::SI_DATA) {
            if ($value === null || $value === "" || $value === $si->default_value)
                $this->savedv[$s] = null;
            else
                $this->savedv[$s] = [1, $value];
        } else if ($value === null || $value === $si->default_value)
            $this->savedv[$s] = null;
        else
            $this->savedv[$s] = [$value, null];
    }
    function update($name, $value) {
        if ($value !== $this->oldv($name)) {
            $this->save($name, $value);
            return true;
        } else
            return false;
    }
    function cleanup_callback($name, $func, $arg = null) {
        if (!isset($this->cleanup_callbacks[$name]))
            $this->cleanup_callbacks[$name] = [$func, null];
        if (func_num_args() > 2)
            $this->cleanup_callbacks[$name][1][] = $arg;
    }

    private function si_curv($name, Si $si, $default_value) {
        if ($si->group && !$si->is_interesting($this))
            error_log("$name: bad group $si->group, not interesting here");
        if ($this->use_req())
            return get($this->req, str_replace(".", "_", $name), $default_value);
        else
            return $this->si_oldv($si, $default_value);
    }
    private function si_oldv(Si $si, $default_value) {
        if ($default_value === null)
            $default_value = $si->default_value;
        if (isset($this->explicit_oldv[$si->name]))
            $val = $this->explicit_oldv[$si->name];
        else if ($si->storage_type & Si::SI_OPT) {
            $val = $this->conf->opt(substr($si->storage(), 4), $default_value);
            if (($si->storage_type & Si::SI_VALUE) && is_bool($val))
                $val = (int) $val;
        } else if ($si->storage_type & Si::SI_DATA)
            $val = $this->conf->setting_data($si->storage(), $default_value);
        else
            $val = $this->conf->setting($si->storage(), $default_value);
        if ($val === $si->invalid_value)
            $val = "";
        return $val;
    }
    private function si_savedv($s, Si $si, $default_value) {
        if (!isset($this->savedv[$s]))
            return $default_value;
        else if ($si->storage_type & Si::SI_DATA)
            return $this->savedv[$s][1];
        else
            return $this->savedv[$s][0];
    }

    function echo_messages_near($name) {
        $msgs = [];
        $status = MessageSet::INFO;
        foreach ($this->near_msgs->messages_at($name, true) as $mx) {
            $msgs[] = ($mx[2] == MessageSet::WARNING ? "Warning: " : "") . $mx[1];
            $status = max($status, $mx[2]);
        }
        if (!empty($msgs)) {
            $xtype = ["xinfo", "xwarning", "xmerror"];
            $this->conf->msg($xtype[$status], $msgs);
        }
    }
    function echo_checkbox_only($name, $extra = null) {
        $extra["id"] = "cb$name";
        $x = $this->curv($name);
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, $extra));
    }
    function echo_checkbox($name, $text, $extra = null) {
        $this->echo_checkbox_only($name, $extra);
        echo "&nbsp;", $this->label($name, $text, true), "<br />\n";
    }
    function echo_checkbox_row($name, $text, $extra = null) {
        echo '<tr><td class="nb">';
        $this->echo_checkbox_only($name, $extra);
        echo '&nbsp;</td><td>', $this->label($name, $text, true), "</td></tr>\n";
    }
    function echo_radio_table($name, $varr) {
        $x = $this->curv($name);
        if ($x === null || !isset($varr[$x]))
            $x = 0;
        echo "<table style=\"margin-top:0.25em\" id=\"{$name}_table\">\n";
        $changejs = "settings_radio_table(" . json_encode_browser($name) . ")";
        foreach ($varr as $k => $text) {
            echo "<tr id=\"{$name}_row_{$k}\" class=\"foldc\"><td class=\"nb\">",
                Ht::radio($name, $k, $k == $x, $this->sjs($name, ["id" => "{$name}_{$k}", "onchange" => $changejs])),
                "&nbsp;</td><td>",
                $this->label($name, $text, true),
                "</td></tr>\n";
        }
        echo "</table>\n", Ht::unstash_script($changejs);
    }
    function render_entry($name, $js = []) {
        $v = $this->curv($name);
        $t = "";
        if (($si = $this->si($name))) {
            if ($si->size && !isset($js["size"]))
                $js["size"] = $si->size;
            if ($si->placeholder && !isset($js["placeholder"]))
                $js["placeholder"] = $si->placeholder;
            if ($si->autogrow)
                $js["class"] = ltrim(get($js, "class", "") . " need-autogrow");
            if ($si->is_date())
                $v = $this->si_render_date_value($v, $si);
            else if ($si->type === "grace")
                $v = $this->si_render_grace_value($v, $si);
            if ($si->parser)
                $t = Ht::hidden("has_$name", 1);
        }
        return Ht::entry($name, $v, $this->sjs($name, $js)) . $t;
    }
    function echo_entry($name) {
        echo $this->render_entry($name);
    }
    function echo_entry_row($name, $description, $hint = null, $js = []) {
        $after_entry = null;
        if (isset($js["after_entry"])) {
            $after_entry = $js["after_entry"];
            unset($js["after_entry"]);
        }
        echo '<tr><td class="lcaption nb">', $this->label($name, $description),
            '</td><td class="lentry">', $this->render_entry($name, $js);
        if ($after_entry)
            echo $after_entry;
        if (($si = $this->si($name)) && ($thint = $this->type_hint($si->type)))
            $hint = ($hint ? $hint . "<br />" : "") . $thint;
        if ($hint)
            echo '<br /><span class="hint">', $hint, "</span>";
        echo "</td></tr>\n";
    }
    function echo_entry_pair($name, $description, $hint = null, $js = []) {
        $after_entry = null;
        if (isset($js["after_entry"])) {
            $after_entry = $js["after_entry"];
            unset($js["after_entry"]);
        }
        echo '<div class="f-i"><div class="f-c">', $this->label($name, $description),
            '</div><div class="f-e">', $this->render_entry($name, $js);
        if ($after_entry)
            echo $after_entry;
        if (($si = $this->si($name)) && ($thint = $this->type_hint($si->type)))
            $hint = ($hint ? $hint . "<br />" : "") . $thint;
        if ($hint)
            echo '<br /><span class="hint">', $hint, "</span>";
        echo "</div></div>\n";
    }
    function render_select($name, $values, $js = []) {
        $v = $this->curv($name);
        $t = "";
        if (($si = $this->si($name)) && $si->parser)
            $t = Ht::hidden("has_$name", 1);
        return Ht::select($name, $values, $v !== null ? $v : 0, $this->sjs($name, $js)) . $t;
    }
    function render_textarea($name, $js = []) {
        $v = $this->curv($name);
        $t = "";
        $rows = 10;
        if (($si = $this->si($name))) {
            if ($si->size)
                $rows = $si->size;
            if ($si->placeholder)
                $js["placeholder"] = $si->placeholder;
            if ($si->autogrow || $si->autogrow === null)
                $js["class"] = ltrim(get($js, "class", "") . " need-autogrow");
            if ($si->parser)
                $t = Ht::hidden("has_$name", 1);
        }
        if (!isset($js["rows"]))
            $js["rows"] = $rows;
        if (!isset($js["cols"]))
            $js["cols"] = 80;
        return Ht::textarea($name, $v, $this->sjs($name, $js)) . $t;
    }
    private function echo_message_base($name, $description, $hint, $class) {
        $si = $this->si($name);
        $si->default_value = $this->conf->message_default_html($name);
        $current = $this->curv($name);
        $description = '<a class="ui q js-foldup" href="#">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="fold', ($current == $si->default_value ? "c" : "o"), '" data-fold="true">',
            '<div class="', $class, ' childfold js-foldup">',
            $this->label($name, $description),
            ' <span class="f-cx fx">(HTML allowed)</span></div>',
            $hint,
            $this->render_textarea($name, ["class" => "fx"]),
            '</div><div class="g"></div>', "\n";
    }
    function echo_message($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "f-cl");
    }
    function echo_message_minor($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "f-cn");
    }

    private function si_render_date_value($v, Si $si) {
        if ($v !== null && $this->use_req())
            return $v;
        else if ($si->date_backup && $this->curv($si->date_backup) == $v)
            return "";
        else if ($si->placeholder !== "N/A" && $si->placeholder !== "none" && $v === 0)
            return "none";
        else if ($v <= 0)
            return "";
        else if ($v == 1)
            return "now";
        else
            return $this->conf->parseableTime($v, true);
    }
    private function si_render_grace_value($v, Si $si) {
        if ($v === null || $v <= 0 || !is_numeric($v))
            return "none";
        if ($v % 3600 == 0)
            return ($v / 3600) . " hr";
        if ($v % 60 == 0)
            return ($v / 60) . " min";
        return sprintf("%d:%02d", intval($v / 60), $v % 60);
    }

    function type_hint($type) {
        if (str_ends_with($type, "date") && !isset($this->hint_status["date"])) {
            $this->hint_status["date"] = true;
            return "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2014-10-31 00:00 UTC-1100” <a href='http://php.net/manual/en/datetime.formats.php'>(more examples)</a>";
        } else if ($type === "grace" && !isset($this->hint_status["grace"])) {
            $this->hint_status["grace"] = true;
            return "Example: “15 min”";
        } else
            return false;
    }


    function execute() {
        global $Now;
        // parse settings
        foreach (Si::$all as $si)
            $this->account($si);

        // check date relationships
        foreach (array("sub_reg" => "sub_sub", "final_soft" => "final_done")
                 as $dn1 => $dn2)
            list($dv1, $dv2) = [$this->savedv($dn1), $this->savedv($dn2)];
            if (!$dv1 && $dv2)
                $this->save($dn1, $dv2);
            else if ($dv2 && $dv1 > $dv2) {
                $si = Si::get($dn1);
                $this->error_at($si, "Must come before " . Si::get($dn2, "title") . ".");
                $this->error_at($dn2);
            }
        if ($this->has_savedv("sub_sub"))
            $this->save("sub_update", $this->savedv("sub_sub"));
        if ($this->conf->opt("defaultSiteContact")) {
            if ($this->has_savedv("opt.contactName")
                && $this->conf->opt("contactName") === $this->savedv("opt.contactName"))
                $this->save("opt.contactName", null);
            if ($this->has_savedv("opt.contactEmail")
                && $this->conf->opt("contactEmail") === $this->savedv("opt.contactEmail"))
                $this->save("opt.contactEmail", null);
        }
        if ($this->has_savedv("resp_active") && $this->savedv("resp_active"))
            foreach (explode(" ", $this->newv("resp_rounds")) as $i => $rname) {
                $isuf = $i ? "_$i" : "";
                if ($this->newv("resp_open$isuf") > $this->newv("resp_done$isuf")) {
                    $si = Si::get("resp_open$isuf");
                    $this->error_at($si, "Must come before " . Si::get("resp_done", "title") . ".");
                    $this->error_at("resp_done$isuf");
                }
            }

        // Setting relationships
        if ($this->has_savedv("sub_open")
            && $this->newv("sub_open", 1) <= 0
            && $this->oldv("sub_open") > 0
            && $this->newv("sub_sub") <= 0)
            $this->save("sub_close", $Now);
        if ($this->has_savedv("msg.clickthrough_submit"))
            $this->save("clickthrough_submit", null);

        // make settings
        $this->changes = [];
        if (!$this->has_error()
            && (!empty($this->savedv) || !empty($this->saved_si))) {
            $tables = "Settings write";
            foreach ($this->need_lock as $t => $need)
                if ($need)
                    $tables .= ", $t write";
            $this->conf->qe_raw("lock tables $tables");

            // load db settings, pre-crosscheck
            $dbsettings = array();
            $result = $this->conf->qe("select name, value, data from Settings");
            while (($row = edb_row($result)))
                $dbsettings[$row[0]] = $row;
            Dbl::free($result);

            // apply settings
            foreach ($this->saved_si as $si) {
                $this->parser($si)->save($this, $si);
            }

            $dv = $av = array();
            foreach ($this->savedv as $n => $v) {
                if (substr($n, 0, 4) === "opt.") {
                    $okey = substr($n, 4);
                    if (array_key_exists($okey, $this->conf->opt_override))
                        $oldv = $this->conf->opt_override[$okey];
                    else
                        $oldv = $this->conf->opt($okey);
                    $vi = Si::$option_is_value[$okey] ? 0 : 1;
                    $basev = $vi ? "" : 0;
                    $newv = $v === null ? $basev : $v[$vi];
                    if ($oldv === $newv)
                        $v = null; // delete override value in database
                    else if ($v === null && $oldv !== $basev && $oldv !== null)
                        $v = $vi ? [0, ""] : [0, null];
                }
                if ($v === null
                    ? !isset($dbsettings[$n])
                    : isset($dbsettings[$n]) && (int) $dbsettings[$n][1] === $v[0] && $dbsettings[$n][2] === $v[1])
                    continue;
                $this->changes[] = $n;
                if ($v !== null)
                    $av[] = [$n, $v[0], $v[1]];
                else
                    $dv[] = $n;
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
            if (!empty($this->changes))
                $this->user->log_activity("Updated settings " . join(", ", $this->changes));
            $this->conf->load_settings();
            foreach ($this->cleanup_callbacks as $cb)
                call_user_func($cb[0], $this, $cb[1]);

            // contactdb may need to hear about changes to shortName
            if ($this->has_savedv("opt.shortName") && ($cdb = Contact::contactdb()))
                Dbl::ql($cdb, "update Conferences set shortName=? where dbName=?", $this->conf->short_name, $this->conf->dbname);
        }
        return !$this->has_error();
    }
    function account(Si $si1) {
        if ($si1->internal)
            return;
        foreach ($this->req_si($si1) as $si) {
            if ($si->disabled || $si->novalue || !$si->type || $si->type === "none") {
                /* ignore changes to disabled/novalue settings */;
            } else if ($si->parser) {
                if ($this->parser($si)->parse($this, $si)) {
                    $this->saved_si[] = $si;
                }
            } else {
                $v = $this->parse_value($si);
                if ($v === null || $v === false)
                    return;
                if (is_int($v) && $v <= 0 && $si->type !== "radio" && $si->type !== "zint")
                    $v = null;
                $this->save($si->name, $v);
                if ($si->ifnonempty)
                    $this->save($si->ifnonempty, $v === null || $v === "" ? null : 1);
            }
        }
    }
    function parse_value(Si $si) {
        global $Now;

        if (!isset($sv->req[$si->name])) {
            $xname = str_replace(".", "_", $si->name);
            if (isset($this->req[$xname]))
                $this->req[$si->name] = $this->req[$xname];
            else if ($si->type === "checkbox" || $si->type === "cdate")
                return 0;
            else
                return null;
        }

        $v = trim($this->req[$si->name]);
        if (($si->placeholder && $si->placeholder === $v)
            || ($si->invalid_value && $si->invalid_value === $v))
            $v = "";

        if ($si->type === "checkbox")
            return $v != "" ? 1 : 0;
        else if ($si->type === "cdate" && $v == "1")
            return 1;
        else if ($si->type === "date" || $si->type === "cdate"
                 || $si->type === "ndate") {
            if ($v == "" || !strcasecmp($v, "N/A") || !strcasecmp($v, "same as PC")
                || $v == "0" || ($si->type !== "ndate" && !strcasecmp($v, "none")))
                return -1;
            else if (!strcasecmp($v, "none"))
                return 0;
            else if (($v = $this->conf->parse_time($v)) !== false)
                return $v;
            $err = "Invalid date.";
        } else if ($si->type === "grace") {
            if (($v = SettingParser::parse_grace($v)) !== null)
                return intval($v);
            $err = "Invalid grace period.";
        } else if ($si->type === "int" || $si->type === "zint") {
            if (preg_match("/\\A[-+]?[0-9]+\\z/", $v))
                return intval($v);
            if ($v == "" && $si->placeholder)
                return 0;
            $err = "Should be a number.";
        } else if ($si->type === "string") {
            // Avoid storing the default message in the database
            if (substr($si->name, 0, 9) == "mailbody_") {
                $t = SettingParser::expand_mail_template(substr($si->name, 9), true);
                $v = cleannl($v);
                if ($t["body"] == $v)
                    return "";
            }
            return $v;
        } else if ($si->type === "simplestring") {
            return simplify_whitespace($v);
        } else if ($si->type === "tag" || $si->type === "tagbase") {
            $tagger = new Tagger($this->user);
            $v = trim($v);
            if ($v === "" && $si->optional)
                return $v;
            $v = $tagger->check($v, $si->type === "tagbase" ? Tagger::NOVALUE : 0);
            if ($v)
                return $v;
            $err = $tagger->error_html;
        } else if ($si->type === "emailheader") {
            $v = MimeText::encode_email_header("", $v);
            if ($v !== false)
                return ($v == "" ? "" : MimeText::decode_header($v));
            $err = "Invalid email header.";
        } else if ($si->type === "emailstring") {
            $v = trim($v);
            if ($v === "" && $si->optional)
                return "";
            else if (validate_email($v) || $v === $this->oldv($si->name, null))
                return $v;
            $err = "Invalid email.";
        } else if ($si->type === "urlstring") {
            $v = trim($v);
            if (($v === "" && $si->optional)
                || preg_match(',\A(?:https?|ftp)://\S+\z,', $v))
                return $v;
            $err = "Invalid URL.";
        } else if ($si->type === "htmlstring") {
            if (($v = CleanHTML::basic_clean($v, $err)) !== false) {
                if ($si->message_default
                    && $v === $this->conf->message_default_html($si->message_default))
                    return "";
                return $v;
            }
            /* $err set by CleanHTML::basic_clean */
        } else if ($si->type === "radio") {
            foreach ($si->values as $allowedv)
                if ((string) $allowedv === $v)
                    return $allowedv;
            $err = "Parse error (unexpected value).";
        } else
            return $v;

        $this->error_at($si, $err);
        return null;
    }

    function changes() {
        return $this->changes;
    }
}

Si::initialize();
