<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

// setting information
class Si {
    public $name;
    public $short_description;
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
    public $autogrow = false;
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

    private function store($name, $key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey) && call_user_func($typecheck, $j->$jkey))
            $this->$key = $j->$jkey;
        else if (isset($j->$jkey))
            trigger_error("setting $name.$jkey format error");
    }

    function __construct($name, $j) {
        assert(!preg_match('/_(?:\$|n|m?\d+)\z/', $name));
        $this->name = $name;
        $this->store($name, "short_description", $j, "name", "is_string");
        foreach (["short_description", "type", "storage", "parser", "ifnonempty", "message_default", "placeholder", "invalid_value", "date_backup"] as $k)
            $this->store($name, $k, $j, $k, "is_string");
        foreach (["internal", "optional", "novalue", "disabled", "autogrow"] as $k)
            $this->store($name, $k, $j, $k, "is_bool");
        $this->store($name, "size", $j, "size", "is_int");
        $this->store($name, "values", $j, "values", "is_array");
        if (isset($j->default_value) && (is_int($j->default_value) || is_string($j->default_value)))
            $this->default_value = $j->default_value;
        if (isset($j->extensible) && $j->extensible === true)
            $this->extensible = self::X_YES;
        else if (isset($j->extensible) && $j->extensible === "word")
            $this->extensible = self::X_WORD;
        else if (isset($j->extensible) && $j->extensible !== false)
            trigger_error("setting $name.extensible format error");
        if (isset($j->group)) {
            if (is_string($j->group))
                $this->group = $j->group;
            else if (is_array($j->group)) {
                $this->group = [];
                foreach ($j->group as $g)
                    if (is_string($g))
                        $this->group[] = $g;
                    else
                        trigger_error("setting $name.group format error");
            }
        }

        if (!$this->type && $this->parser)
            $this->type = "special";
        $s = $this->storage ? : $name;
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
        if (is_string($groups))
            $groups = array($groups);
        foreach ($groups as $g) {
            if (isset(SettingGroup::$map[$g]))
                $g = SettingGroup::$map[$g];
            if (!isset(SettingGroup::$all[$g]))
                error_log("$this->name: bad group $g");
            else if ($sv->group_is_interesting($g))
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
                $si->short_description .= " (" . htmlspecialchars($m[2]) . ")";
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

    static function initialize() {
        global $ConfSitePATH;
        $fname = "$ConfSitePATH/etc/settings.json";
        $info = self::read([], file_get_contents($fname), $fname);
        if (($settinginfo_include = opt("settinginfo_include"))) {
            if (!is_array($settinginfo_include))
                $settinginfo_include = array($settinginfo_include);
            foreach ($settinginfo_include as $k => $si) {
                if (preg_match(',\A\s*\{\s*\",s', $si))
                    $info = self::read($info, $si, "include entry $k");
                else
                    foreach (expand_includes($si) as $f)
                        if (($x = file_get_contents($f)))
                            $info = self::read($info, $x, $f);
            }
        }

        foreach ($info as $k => $v) {
            if (isset($v["require"])) {
                foreach (expand_includes($v["require"]) as $f)
                    require_once $f;
            }
            $class = "Si";
            if (isset($v["info_class"]))
                $class = $v["info_class"];
            self::$all[$k] = new $class($k, (object) $v);
        }
    }
}

class SettingParser {
    function parse(SettingValues $sv, Si $si) {
        return false;
    }
    function save(SettingValues $sv, Si $si) {
    }
}

class SettingRenderer {
    function render(SettingValues $sv) {
    }
    function crosscheck(SettingValues $sv) {
    }
}

class SettingValues extends MessageSet {
    public $conf;
    public $user;
    public $interesting_groups = array();

    private $parsers = array();
    public $save_callbacks = array();
    public $need_lock = array();
    public $changes = array();

    public $req = array();
    public $req_files = array();
    public $savedv = array();
    public $explicit_oldv = array();
    private $hint_status = array();
    private $has_req = array();
    private $near_msgs = null;

    function __construct($user) {
        parent::__construct();
        $this->conf = $user->conf;
        $this->user = $user;
        $this->near_msgs = new MessageSet;
    }

    function use_req() {
        return $this->has_error();
    }
    static private function check_error_field($field, &$html) {
        if ($field instanceof Si) {
            if ($field->short_description && $html !== false)
                $html = htmlspecialchars($field->short_description) . ": " . $html;
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
    function report() {
        $msgs = array();
        foreach ($this->messages(true) as $mx)
            $msgs[] = ($mx[2] == MessageSet::WARNING ? "Warning: " : "") . $mx[1];
        $mt = '<div class="multimessage"><div class="mmm">' . join('</div><div class="mmm">', $msgs) . '</div></div>';
        if (!empty($msgs) && $this->has_error())
            Conf::msg_error($mt, true);
        else if (!empty($msgs))
            Conf::msg_warning($mt, true);
    }
    function parser(Si $si) {
        if ($si->parser) {
            if (!isset($this->parsers[$si->parser]))
                $this->parsers[$si->parser] = new $si->parser;
            return $this->parsers[$si->parser];
        } else
            return null;
    }
    function group_is_interesting($g) {
        return isset($this->interesting_groups[$g]);
    }

    function label($name, $html, $label_id = null) {
        $name1 = is_array($name) ? $name[0] : $name;
        foreach (is_array($name) ? $name : array($name) as $n)
            if ($this->has_problem_at($n)) {
                $html = '<span class="setting_error">' . $html . '</span>';
                break;
            }
        if ($label_id !== false) {
            $label_id = $label_id ? : $name1;
            if (($pos = strpos($html, "<input")) !== false)
                $html = Ht::label(substr($html, 0, $pos), $label_id) . substr($html, $pos);
            else
                $html = Ht::label($html, $label_id);
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
    function req_si(Si $si) {
        $xname = str_replace(".", "_", $si->name);
        $xsis = [];
        foreach (get($this->has_req, $xname, []) as $suffix) {
            $xsi = $this->si($si->name . $suffix);
            if ($xsi->parser)
                $has_value = truthy(get($this->req, "has_$xname$suffix"));
            else
                $has_value = isset($this->req["$xname$suffix"])
                    || (($xsi->type === "cdate" || $xsi->type === "checkbox")
                        && truthy(get($this->req, "has_$xname$suffix")));
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
        else if ($si->storage_type & Si::SI_OPT)
            $val = $this->conf->opt(substr($si->storage(), 4), $default_value);
        else if ($si->storage_type & Si::SI_DATA)
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
            $mt = '<div class="multimessage"><div class="mmm">' . join('</div><div class="mmm">', $msgs) . '</div></div>';
            $xtype = ["xinfo", "xwarning", "xmerror"];
            $this->conf->msg($xtype[$status], $mt);
        }
    }
    function echo_checkbox_only($name, $onchange = null) {
        $x = $this->curv($name);
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, array("onchange" => $onchange, "id" => "cb$name")));
    }
    function echo_checkbox($name, $text, $onchange = null) {
        $this->echo_checkbox_only($name, $onchange);
        echo "&nbsp;", $this->label($name, $text, true), "<br />\n";
    }
    function echo_checkbox_row($name, $text, $onchange = null) {
        echo '<tr><td class="nb">';
        $this->echo_checkbox_only($name, $onchange);
        echo '&nbsp;</td><td>', $this->label($name, $text, true), "</td></tr>\n";
    }
    function echo_radio_table($name, $varr) {
        $x = $this->curv($name);
        if ($x === null || !isset($varr[$x]))
            $x = 0;
        echo "<table style=\"margin-top:0.25em\">\n";
        foreach ($varr as $k => $text) {
            echo '<tr><td class="nb">',
                Ht::radio($name, $k, $k == $x, $this->sjs($name, array("id" => "{$name}_{$k}"))),
                "&nbsp;</td><td>";
            if (is_array($text))
                echo $this->label($name, $text[0], true), "<br /><small>", $text[1], "</small>";
            else
                echo $this->label($name, $text, true);
            echo "</td></tr>\n";
        }
        echo "</table>\n";
    }
    function render_entry($name, $js = []) {
        $v = $this->curv($name);
        $t = "";
        if (($si = $this->si($name))) {
            if ($si->size)
                $js["size"] = $si->size;
            if ($si->placeholder)
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
        return Ht::select($name, $values, $v, $this->sjs($name, $js)) . $t;
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
            if ($si->autogrow)
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
        $description = '<a class="q" href="#" onclick="return foldup(this,event)">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="fold', ($current == $si->default_value ? "c" : "o"), '" data-fold="true">',
            '<div class="', $class, ' childfold" onclick="return foldup(this,event)">',
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
            return $si->placeholder;
        else if ($si->placeholder !== "N/A" && $si->placeholder !== "none" && $v === 0)
            return "none";
        else if ($v <= 0)
            return $si->placeholder;
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

    static function make_request($user) {
        $sv = new SettingValues($user);
        foreach ($user->conf->session("settings_highlight", []) as $f => $v)
            $sv->msg($f, null, $v);
        $user->conf->save_session("settings_highlight", null);
        $got = [];
        foreach ($_POST as $k => $v) {
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
        foreach ($_FILES as $f => $finfo)
            if (($e = $finfo["error"]) == UPLOAD_ERR_OK) {
                if (is_uploaded_file($finfo["tmp_name"]))
                    $sv->req_files[$f] = $finfo;
            }
        return $sv;
    }
}

class SettingGroup {
    public $name;
    public $description;
    public $priority;
    private $render = array();

    static public $all;
    static public $map;
    static private $sorted = false;

    function __construct($name, $description, $priority) {
        $this->name = $name;
        $this->description = $description;
        $this->priority = $priority;
    }
    function add_renderer($priority, SettingRenderer $renderer) {
        $x = [$priority, count($this->render), $renderer];
        $this->render[] = $x;
        self::$sorted = false;
    }
    function render(SettingValues $sv) {
        self::sort();
        foreach ($this->render as $r)
            $r[2]->render($sv);
    }
    static function crosscheck(SettingValues $sv, $groupname) {
        $sv->interesting_groups[$groupname] = true;
        self::sort();
        foreach (self::$all as $name => $group) {
            foreach ($group->render as $r)
                $r[2]->crosscheck($sv);
        }
    }

    static function register($name, $description, $priority, SettingRenderer $renderer) {
        if (isset(self::$map[$name]))
            $name = self::$map[$name];
        if (!isset(self::$all[$name]))
            self::$all[$name] = new SettingGroup($name, $description, $priority);
        if ($description && !self::$all[$name]->description)
            self::$all[$name]->description = $description;
        self::$all[$name]->add_renderer($priority, $renderer);
    }
    static function register_synonym($new_name, $old_name) {
        assert(isset(self::$all[$old_name]) && !isset(self::$map[$old_name]));
        assert(!isset(self::$all[$new_name]) && !isset(self::$map[$new_name]));
        self::$map[$new_name] = $old_name;
    }
    static function all() {
        self::sort();
        return self::$all;
    }

    static private function sort() {
        if (self::$sorted)
            return;
        uasort(self::$all, function ($a, $b) {
            if ($a->priority != $b->priority)
                return $a->priority < $b->priority ? -1 : 1;
            else
                return strcasecmp($a->name, $b->name);
        });
        foreach (self::$all as $name => $group)
            usort($group->render, function ($a, $b) {
                if ($a[0] != $b[0])
                    return $a[0] < $b[0] ? -1 : 1;
                if ($a[1] != $b[1])
                    return $a[1] < $b[1] ? -1 : 1;
                return 0;
            });
        self::$sorted = true;
    }
}

Si::initialize();
$Sv = SettingValues::make_request($Me);

function choose_setting_group() {
    global $Conf;
    $req_group = req("group");
    if (!$req_group && preg_match(',\A/\w+\z,', Navigation::path()))
        $req_group = substr(Navigation::path(), 1);
    $want_group = $req_group;
    if (!$want_group && isset($_SESSION["sg"])) // NB not conf-specific session, global
        $want_group = $_SESSION["sg"];
    if (isset(SettingGroup::$map[$want_group]))
        $want_group = SettingGroup::$map[$want_group];
    if (!isset(SettingGroup::$all[$want_group])) {
        if ($Conf->timeAuthorViewReviews())
            $want_group = "decisions";
        else if ($Conf->deadlinesAfter("sub_sub") || $Conf->time_review_open())
            $want_group = "reviews";
        else
            $want_group = "sub";
    }
    if ($want_group != $req_group && empty($_POST) && !req("post"))
        redirectSelf(["group" => $want_group]);
    return $want_group;
}
$Group = $_REQUEST["group"] = $_GET["group"] = choose_setting_group();
$_SESSION["sg"] = $Group;

// maybe set $Opt["contactName"] and $Opt["contactEmail"]
$Conf->site_contact();


function parseGrace($v) {
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

function expandMailTemplate($name, $default) {
    global $null_mailer;
    if (!isset($null_mailer))
        $null_mailer = new HotCRPMailer(null, null, array("width" => false));
    return $null_mailer->expand_template($name, $default);
}

function parse_value(SettingValues $sv, Si $si) {
    global $Now;

    if (!isset($sv->req[$si->name])) {
        $xname = str_replace(".", "_", $si->name);
        if (isset($sv->req[$xname]))
            $sv->req[$si->name] = $sv->req[$xname];
        else if ($si->type === "checkbox" || $si->type === "cdate")
            return 0;
        else
            return null;
    }

    $v = trim($sv->req[$si->name]);
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
        else if (($v = $sv->conf->parse_time($v)) !== false)
            return $v;
        $err = "Invalid date.";
    } else if ($si->type === "grace") {
        if (($v = parseGrace($v)) !== null)
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
            $t = expandMailTemplate(substr($si->name, 9), true);
            $v = cleannl($v);
            if ($t["body"] == $v)
                return "";
        }
        return $v;
    } else if ($si->type === "simplestring") {
        return simplify_whitespace($v);
    } else if ($si->type === "tag" || $si->type === "tagbase") {
        $tagger = new Tagger($sv->user);
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
        else if (validate_email($v) || $v === $sv->oldv($si->name, null))
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
                && $v === $sv->conf->message_default_html($si->message_default))
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

    $sv->error_at($si, $err);
    return null;
}

function truthy($x) {
    return !($x === null || $x === 0 || $x === false
             || $x === "" || $x === "0" || $x === "false");
}

function opt_yes_no_optional($name) {
    global $Conf;
    if (($x = $Conf->opt($name)) === 1 || $x === true)
        return 1;
    if ($x === 2)
        return 2;
    return 0;
}

function account_value(SettingValues $sv, Si $si1) {
    if ($si1->internal)
        return;
    foreach ($sv->req_si($si1) as $si) {
        if ($si->disabled || $si->novalue || !$si->type || $si->type === "none")
            /* ignore changes to disabled/novalue settings */;
        else if ($si->parser) {
            $p = $sv->parser($si);
            if ($p->parse($sv, $si))
                $sv->save_callbacks[$si->name] = $si;
        } else {
            $v = parse_value($sv, $si);
            if ($v === null || $v === false)
                return;
            if (is_int($v) && $v <= 0 && $si->type !== "radio" && $si->type !== "zint")
                $v = null;
            $sv->save($si->name, $v);
            if ($si->ifnonempty)
                $sv->save($si->ifnonempty, $v === null || $v === "" ? null : 1);
        }
    }
}

function do_setting_update(SettingValues $sv) {
    global $Group, $Now;
    // parse settings
    foreach (Si::$all as $si)
        account_value($sv, $si);

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "final_soft" => "final_done")
             as $dn1 => $dn2)
        list($dv1, $dv2) = [$sv->savedv($dn1), $sv->savedv($dn2)];
        if (!$dv1 && $dv2)
            $sv->save($dn1, $dv2);
        else if ($dv2 && $dv1 > $dv2) {
            $si = Si::get($dn1);
            $sv->error_at($si, "Must come before " . Si::get($dn2, "short_description") . ".");
            $sv->error_at($dn2);
        }
    if ($sv->has_savedv("sub_sub"))
        $sv->save("sub_update", $sv->savedv("sub_sub"));
    if (opt("defaultSiteContact")) {
        if ($sv->has_savedv("opt.contactName")
            && opt("contactName") === $sv->savedv("opt.contactName"))
            $sv->save("opt.contactName", null);
        if ($sv->has_savedv("opt.contactEmail")
            && opt("contactEmail") === $sv->savedv("opt.contactEmail"))
            $sv->save("opt.contactEmail", null);
    }
    if ($sv->has_savedv("resp_active") && $sv->savedv("resp_active"))
        foreach (explode(" ", $sv->newv("resp_rounds")) as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if ($sv->newv("resp_open$isuf") > $sv->newv("resp_done$isuf")) {
                $si = Si::get("resp_open$isuf");
                $sv->error_at($si, "Must come before " . Si::get("resp_done", "short_description") . ".");
                $sv->error_at("resp_done$isuf");
            }
        }

    // update 'papersub'
    if ($sv->has_savedv("pc_seeall")) {
        // see also conference.php
        if ($sv->savedv("pc_seeall") <= 0)
            $x = "timeSubmitted>0";
        else
            $x = "timeWithdrawn<=0";
        $num = $sv->conf->fetch_ivalue("select paperId from Paper where $x limit 1") ? 1 : 0;
        if ($num != $sv->conf->setting("papersub"))
            $sv->save("papersub", $num);
    }

    // Setting relationships
    if ($sv->has_savedv("sub_open")
        && $sv->newv("sub_open", 1) <= 0
        && $sv->oldv("sub_open") > 0
        && $sv->newv("sub_sub") <= 0)
        $sv->save("sub_close", $Now);
    if ($sv->has_savedv("msg.clickthrough_submit"))
        $sv->save("clickthrough_submit", null);

    // make settings
    $sv->changes = [];
    if (!$sv->has_error()
        && (!empty($sv->savedv) || !empty($sv->save_callbacks))) {
        $tables = "Settings write";
        foreach ($sv->need_lock as $t => $need)
            if ($need)
                $tables .= ", $t write";
        $sv->conf->qe_raw("lock tables $tables");

        // load db settings, pre-crosscheck
        $dbsettings = array();
        $result = $sv->conf->qe("select name, value, data from Settings");
        while (($row = edb_row($result)))
            $dbsettings[$row[0]] = $row;
        Dbl::free($result);

        // apply settings
        foreach ($sv->save_callbacks as $si) {
            $p = $sv->parser($si);
            $p->save($sv, $si);
        }

        $dv = $av = array();
        foreach ($sv->savedv as $n => $v) {
            if (substr($n, 0, 4) === "opt.") {
                $okey = substr($n, 4);
                if (array_key_exists($okey, $sv->conf->opt_override))
                    $oldv = $sv->conf->opt_override[$okey];
                else
                    $oldv = $sv->conf->opt($okey);
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
            $sv->changes[] = $n;
            if ($v !== null)
                $av[] = [$n, $v[0], $v[1]];
            else
                $dv[] = $n;
        }
        if (!empty($dv)) {
            $sv->conf->qe("delete from Settings where name?a", $dv);
            //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query("delete from Settings where name?a", $dv)));
        }
        if (!empty($av)) {
            $sv->conf->qe("insert into Settings (name, value, data) values ?v on duplicate key update value=values(value), data=values(data)", $av);
            //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query("insert into Settings (name, value, data) values ?v on duplicate key update value=values(value), data=values(data)", $av)));
        }

        $sv->conf->qe_raw("unlock tables");
        if (!empty($sv->changes))
            $sv->user->log_activity("Updated settings " . join(", ", $sv->changes));
        $sv->conf->load_settings();

        // contactdb may need to hear about changes to shortName
        if ($sv->has_savedv("opt.shortName") && ($cdb = Contact::contactdb()))
            Dbl::ql($cdb, "update Conferences set shortName=? where dbName=?", $sv->conf->short_name, $sv->conf->dbname);
    }

    if (!$sv->has_error()) {
        $sv->conf->save_session("settings_highlight", $sv->message_field_map());
        if (!empty($sv->changes))
            $sv->conf->confirmMsg("Changes saved.");
        else
            $sv->conf->warnMsg("No changes.");
        $sv->report();
        redirectSelf();
    }
}
if (isset($_REQUEST["update"]) && check_post())
    do_setting_update($Sv);
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();

SettingGroup::crosscheck($Sv, $Group);

$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . SettingGroup::$all[$Group]->description . "</strong>", "settings", actionBar());
echo Ht::unstash(); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings", "group=$Group"), array("id" => "settingsform"));

echo '<div class="leftmenu_menucontainer"><div class="leftmenu_list">';
foreach (SettingGroup::all() as $g) {
    if ($g->name === $Group)
        echo '<div class="leftmenu_item_on">', $g->description, '</div>';
    else
        echo '<div class="leftmenu_item">',
            '<a href="', hoturl("settings", "group={$g->name}"), '">', $g->description, '</a></div>';
}
echo "</div></div>\n",
    '<div class="leftmenu_content_container"><div class="leftmenu_content">',
    '<div class="leftmenu_body">';
Ht::stash_script("jQuery(\".leftmenu_item\").click(divclick)");

function doActionArea($top) {
    echo '<div class="aab aabr aabig">',
        '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn btn-default"]), '</div>',
        '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
        '<hr class="c" /></div>';
}

echo '<div class="aahc">';
doActionArea(true);

echo "<div>";
$Sv->report();
$Sv->interesting_groups[$Group] = true;
SettingGroup::$all[$Group]->render($Sv);
echo "</div>";

doActionArea(false);
echo "</div></div></div></div></form>\n";

Ht::stash_script("hiliter_children('#settingsform');jQuery('textarea').autogrow()");
$Conf->footer();
