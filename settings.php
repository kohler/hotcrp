<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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
    public $ifnonempty;
    public $message_default;
    public $date_backup;

    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;

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

    public function __construct($name, $j) {
        assert(!preg_match('/_(?:\$|n|\d+)\z/', $name));
        $this->name = $name;
        $this->store($name, "short_description", $j, "name", "is_string");
        foreach (["short_description", "type", "storage", "parser", "ifnonempty", "message_default", "placeholder", "invalid_value", "date_backup"] as $k)
            $this->store($name, $k, $j, $k, "is_string");
        foreach (["internal", "optional", "novalue", "disabled"] as $k)
            $this->store($name, $k, $j, $k, "is_bool");
        $this->store($name, "size", $j, "size", "is_int");
        $this->store($name, "values", $j, "values", "is_array");
        if (isset($j->default_value) && (is_int($j->default_value) || is_string($j->default_value)))
            $this->default_value = $j->default_value;
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

    public function is_date() {
        return str_ends_with($this->type, "date");
    }

    public function storage() {
        return $this->storage ? : $this->name;
    }

    public function is_interesting(SettingValues $sv) {
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

    static public function get($name, $k = null) {
        if (!isset(self::$all[$name])
            && preg_match('/\A(.*)(_(?:\$|n|\d+))\z/', $name, $m)
            && isset(self::$all[$m[1]])) {
            $si = clone self::$all[$m[1]];
            $si->name = $name;
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

    static public function initialize() {
        global $ConfSitePATH, $Opt;
        $fname = "$ConfSitePATH/src/settinginfo.json";
        $info = self::read([], file_get_contents($fname), $fname);
        if (isset($Opt["settinginfo_include"])
            && ($settinginfo_include = $Opt["settinginfo_include"])) {
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
    public function parse($sv, $si) {
        return false;
    }
    public function save($sv, $si) {
    }
}

class SettingRenderer {
    public function render($sv) {
    }
    public function crosscheck($sv) {
    }
}

class SettingValues {
    private $errf = array();
    private $errmsg = array();
    private $warnmsg = array();
    public $interesting_groups = array();
    public $warnings_reported = false;

    private $parsers = array();
    public $save_callbacks = array();
    public $need_lock = array();

    public $req = array();
    public $savedv = array();
    public $explicit_oldv = array();
    private $hint_status = array();

    public function __construct() {
    }

    public function use_req() {
        return count($this->errmsg) > 0;
    }
    public function has_errors() {
        return count($this->errmsg) > 0;
    }
    public function error_count() {
        return count($this->errmsg);
    }
    public function has_error($field) {
        return isset($this->errf[$field]);
    }
    static private function check_error_field($field, &$html) {
        if ($field instanceof Si) {
            if ($field->short_description && $html !== false)
                $html = htmlspecialchars($field->short_description) . ": " . $html;
            return $field->name;
        } else
            return $field;
    }
    public function set_error($field, $html = false) {
        $fname = self::check_error_field($field, $html);
        if ($fname)
            $this->errf[$fname] = 2;
        if ($html !== false)
            $this->errmsg[] = $html;
        return false;
    }
    public function set_warning($field, $html = false) {
        $fname = self::check_error_field($field, $html);
        if ($field && !isset($this->errf[$field]))
            $this->errf[$field] = 1;
        if ($html !== false)
            $this->warnmsg[] = $html;
        return false;
    }
    public function error_fields() {
        return $this->errf;
    }
    public function report() {
        $msgs = array();
        $any_errors = false;
        foreach ($this->errmsg as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = $any_errors = $m;
        foreach ($this->warnmsg as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = "Warning: " . $m;
        $mt = '<div class="multimessage"><div>' . join('</div><div>', $msgs) . '</div></div>';
        if (count($msgs) && $any_errors)
            Conf::msg_error($mt);
        else if (count($msgs))
            Conf::msg_warning($mt);
        $this->warnings_reported = true;
    }
    public function parser($si) {
        if ($si->parser) {
            if (!isset($this->parsers[$si->parser]))
                $this->parsers[$si->parser] = new $si->parser;
            return $this->parsers[$si->parser];
        } else
            return null;
    }
    public function group_is_interesting($g) {
        return isset($this->interesting_groups[$g]);
    }

    public function label($name, $html, $label_id = null) {
        $name1 = is_array($name) ? $name[0] : $name;
        foreach (is_array($name) ? $name : array($name) as $n)
            if ($this->has_error($n)) {
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
    public function sjs($name, $extra = array()) {
        $x = ["id" => $name];
        if (Si::get($name, "disabled"))
            $x["disabled"] = true;
        foreach ($extra as $k => $v)
            $x[$k] = $v;
        if ($this->has_error($name))
            $x["class"] = trim("setting_error " . (get($x, "class") ? : ""));
        return $x;
    }

    public function si($name) {
        $si = Si::get($name);
        if (!$si)
            error_log(caller_landmark(2) . ": setting $name: missing information");
        return $si;
    }

    public function curv($name, $default_value = null) {
        return $this->si_curv($name, $this->si($name), $default_value);
    }
    public function oldv($name, $default_value = null) {
        return $this->si_oldv($this->si($name), $default_value);
    }
    public function reqv($name, $default_value = null) {
        $name = str_replace(".", "_", $name);
        return get($this->req, $name, $default_value);
    }
    public function has_savedv($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv);
    }
    public function has_interest($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv)
            || $si->is_interesting($this);
    }
    public function savedv($name, $default_value = null) {
        $si = $this->si($name);
        return $this->si_savedv($si->storage(), $si, $default_value);
    }
    public function newv($name, $default_value = null) {
        $si = $this->si($name);
        $s = $si->storage();
        if (array_key_exists($s, $this->savedv))
            return $this->si_savedv($s, $si, $default_value);
        else
            return $this->si_oldv($si, $default_value);
    }

    public function set_oldv($name, $value) {
        $this->explicit_oldv[$name] = $value;
    }
    public function save($name, $value) {
        global $Conf;
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
                $this->savedv[$s] = [$Conf->setting($s, 0), $Conf->setting_data($s, null)];
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
    public function update($name, $value) {
        if ($value !== $this->oldv($name)) {
            $this->save($name, $value);
            return true;
        } else
            return false;
    }

    private function si_curv($name, $si, $default_value) {
        if ($si && $si->group && !$si->is_interesting($this))
            error_log("$name: bad group $si->group, not interesting here");
        if ($this->use_req())
            return get($this->req, str_replace(".", "_", $name), $default_value);
        else
            return $this->si_oldv($si, $default_value);
    }
    private function si_oldv($si, $default_value) {
        global $Conf, $Opt;
        if ($default_value === null)
            $default_value = $si->default_value;
        if (isset($this->explicit_oldv[$si->name]))
            $val = $this->explicit_oldv[$si->name];
        else if ($si->storage_type & Si::SI_OPT)
            $val = get($Opt, substr($si->name, 4), $default_value);
        else if ($si->storage_type & Si::SI_DATA)
            $val = $Conf->setting_data($si->name, $default_value);
        else
            $val = $Conf->setting($si->name, $default_value);
        if ($val === $si->invalid_value)
            $val = "";
        return $val;
    }
    private function si_savedv($s, $si, $default_value) {
        if (!isset($this->savedv[$s]))
            return $default_value;
        else if ($si->storage_type & Si::SI_DATA)
            return $this->savedv[$s][1];
        else
            return $this->savedv[$s][0];
    }

    public function echo_checkbox($name, $text, $onchange = null) {
        $x = $this->curv($name);
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, array("onchange" => $onchange, "id" => "cb$name"))),
            "&nbsp;",
            $this->label($name, $text, true),
            "<br />\n";
    }
    public function echo_checkbox_row($name, $text, $onchange = null) {
        $x = $this->curv($name);
        echo '<tr><td class="nw">',
            Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, array("onchange" => $onchange, "id" => "cb$name"))),
            "&nbsp;</td><td>",
            $this->label($name, $text, true),
            "</td></tr>\n";
    }
    public function echo_radio_table($name, $varr) {
        $x = $this->curv($name);
        if ($x === null || !isset($varr[$x]))
            $x = 0;
        echo "<table style=\"margin-top:0.25em\">\n";
        foreach ($varr as $k => $text) {
            echo '<tr><td class="nw">',
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
    public function render_entry($name, $js = []) {
        $v = $this->curv($name);
        $t = "";
        if (($si = $this->si($name))) {
            if ($si->size)
                $js["size"] = $si->size;
            if ($si->placeholder)
                $js["placeholder"] = $si->placeholder;
            if ($si->is_date())
                $v = $this->si_render_date_value($v, $si);
            else if ($si->type === "grace")
                $v = $this->si_render_grace_value($v, $si);
            if ($si->parser)
                $t = Ht::hidden("has_$name", 1);
        }
        return Ht::entry($name, $v, $this->sjs($name, $js)) . $t;
    }
    public function echo_entry($name) {
        echo $this->render_entry($name);
    }
    public function echo_entry_row($name, $description, $hint = null, $after_entry = null) {
        echo '<tr><td class="lcaption nw">', $this->label($name, $description),
            '</td><td class="lentry">', $this->render_entry($name);
        if ($after_entry)
            echo $after_entry;
        if (($si = $this->si($name)) && ($thint = $this->type_hint($si->type)))
            $hint = ($hint ? $hint . "<br />" : "") . $thint;
        if ($hint)
            echo '<br /><span class="hint">', $hint, "</span>";
        echo "</td></tr>\n";
    }
    private function echo_message_base($name, $description, $hint, $class) {
        global $Conf;
        $si = $this->si($name);
        $rows = ($si ? $si->size : 0) ? : 10;
        $default = $Conf->message_default_html($name);
        $current = $this->curv($name, $default);
        $description = '<a class="q" href="#" onclick="return foldup(this,event)">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="fold', ($current == $default ? "c" : "o"), '" data-fold="true">',
            '<div class="', $class, ' childfold" onclick="return foldup(this,event)">',
            $this->label($name, $description),
            ' <span class="f-cx fx">(HTML allowed)</span></div>',
            $hint,
            Ht::textarea($name, $current, $this->sjs($name, array("class" => "fx", "rows" => $rows, "cols" => 80))),
            '</div><div class="g"></div>', "\n";
    }
    public function echo_message($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "f-cl");
    }
    public function echo_message_minor($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "f-cn");
    }

    private function si_render_date_value($v, $si) {
        global $Conf;
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
            return $Conf->parseableTime($v, true);
    }
    private function si_render_grace_value($v, $si) {
        if ($v === null || $v <= 0 || !is_numeric($v))
            return "none";
        if ($v % 3600 == 0)
            return ($v / 3600) . " hr";
        if ($v % 60 == 0)
            return ($v / 60) . " min";
        return sprintf("%d:%02d", intval($v / 60), $v % 60);
    }

    public function type_hint($type) {
        if (str_ends_with($type, "date") && !isset($this->hint_status["date"])) {
            $this->hint_status["date"] = true;
            return "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2014-10-31 00:00 UTC-1100” <a href='http://php.net/manual/en/datetime.formats.php'>(more examples)</a>";
        } else if ($type === "grace" && !isset($this->hint_status["grace"])) {
            $this->hint_status["grace"] = true;
            return "Example: “15 min”";
        } else
            return false;
    }

    static public function make_request() {
        global $Conf;
        $sv = new SettingValues;
        $sv->errf = $Conf->session("settings_highlight", array());
        $Conf->save_session("settings_highlight", null);
        foreach ($_POST as $k => $v)
            $sv->req[$k] = $v;
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

    public function __construct($name, $description, $priority) {
        $this->name = $name;
        $this->description = $description;
        $this->priority = $priority;
    }
    public function add_renderer($priority, SettingRenderer $renderer) {
        $x = [$priority, count($this->render), $renderer];
        $this->render[] = $x;
        self::$sorted = false;
    }
    public function render($sv) {
        self::sort();
        foreach ($this->render as $r)
            $r[2]->render($sv);
    }
    static public function crosscheck($sv, $groupname) {
        $sv->interesting_groups[$groupname] = true;
        self::sort();
        foreach (self::$all as $name => $group) {
            foreach ($group->render as $r)
                $r[2]->crosscheck($sv);
        }
    }

    static public function register($name, $description, $priority, SettingRenderer $renderer) {
        if (isset(self::$map[$name]))
            $name = self::$map[$name];
        if (!isset(self::$all[$name]))
            self::$all[$name] = new SettingGroup($name, $description, $priority);
        if ($description && !self::$all[$name]->description)
            self::$all[$name]->description = $description;
        self::$all[$name]->add_renderer($priority, $renderer);
    }
    static public function register_synonym($new_name, $old_name) {
        assert(isset(self::$all[$old_name]) && !isset(self::$map[$old_name]));
        assert(!isset(self::$all[$new_name]) && !isset(self::$map[$new_name]));
        self::$map[$new_name] = $old_name;
    }
    static public function all() {
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
$Sv = SettingValues::make_request();

function choose_setting_group() {
    global $Conf;
    $Group = get($_REQUEST, "group");
    if (!$Group && preg_match(',\A/(\w+)\z,i', Navigation::path()))
        $Group = substr(Navigation::path(), 1);
    if (isset(SettingGroup::$map[$Group]))
        $Group = SettingGroup::$map[$Group];
    if (!isset(SettingGroup::$all[$Group])) {
        if ($Conf->timeAuthorViewReviews())
            $Group = "decisions";
        else if ($Conf->deadlinesAfter("sub_sub") || $Conf->time_review_open())
            $Group = "reviews";
        else
            $Group = "sub";
    }
    return $Group;
}
$Group = $_REQUEST["group"] = $_GET["group"] = choose_setting_group();

// maybe set $Opt["contactName"] and $Opt["contactEmail"]
Contact::site_contact();


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

function unparse_setting_error($info, $text) {
    if ($info->short_description)
        return "$info->short_description: $text";
    else
        return $text;
}

function parse_value($sv, $name, $info) {
    global $Conf, $Me, $Now, $Opt;

    if (!isset($sv->req[$name])) {
        $xname = str_replace(".", "_", $name);
        if (isset($sv->req[$xname]))
            $sv->req[$name] = $sv->req[$xname];
        else if ($info->type === "checkbox" || $info->type === "cdate")
            return 0;
        else
            return null;
    }

    $v = trim($sv->req[$name]);
    if (($info->placeholder && $info->placeholder === $v)
        || ($info->invalid_value && $info->invalid_value === $v))
        $v = "";

    if ($info->type === "checkbox")
        return $v != "" ? 1 : 0;
    else if ($info->type === "cdate" && $v == "1")
        return 1;
    else if ($info->type === "date" || $info->type === "cdate"
             || $info->type === "ndate") {
        if ($v == "" || !strcasecmp($v, "N/A") || !strcasecmp($v, "same as PC")
            || $v == "0" || ($info->type !== "ndate" && !strcasecmp($v, "none")))
            return -1;
        else if (!strcasecmp($v, "none"))
            return 0;
        else if (($v = $Conf->parse_time($v)) !== false)
            return $v;
        else
            $err = unparse_setting_error($info, "Invalid date.");
    } else if ($info->type === "grace") {
        if (($v = parseGrace($v)) !== null)
            return intval($v);
        else
            $err = unparse_setting_error($info, "Invalid grace period.");
    } else if ($info->type === "int" || $info->type === "zint") {
        if (preg_match("/\\A[-+]?[0-9]+\\z/", $v))
            return intval($v);
        else
            $err = unparse_setting_error($info, "Should be a number.");
    } else if ($info->type === "string") {
        // Avoid storing the default message in the database
        if (substr($name, 0, 9) == "mailbody_") {
            $t = expandMailTemplate(substr($name, 9), true);
            $v = cleannl($v);
            if ($t["body"] == $v)
                return "";
        }
        return $v;
    } else if ($info->type === "simplestring") {
        return simplify_whitespace($v);
    } else if ($info->type === "tag" || $info->type === "tagbase") {
        $tagger = new Tagger($Me);
        $v = trim($v);
        if ($v === "" && $info->optional)
            return $v;
        $v = $tagger->check($v, $info->type === "tagbase" ? Tagger::NOVALUE : 0);
        if ($v)
            return $v;
        $err = unparse_setting_error($info, $tagger->error_html);
    } else if ($info->type === "emailheader") {
        $v = MimeText::encode_email_header("", $v);
        if ($v !== false)
            return ($v == "" ? "" : MimeText::decode_header($v));
        $err = unparse_setting_error($info, "Invalid email header.");
    } else if ($info->type === "emailstring") {
        $v = trim($v);
        if ($v === "" && $info->optional)
            return "";
        else if (validate_email($v) || $v === $v_active)
            return $v;
        else
            $err = unparse_setting_error($info, "Invalid email.");
    } else if ($info->type === "urlstring") {
        $v = trim($v);
        if (($v === "" && $info->optional)
            || preg_match(',\A(?:https?|ftp)://\S+\z,', $v))
            return $v;
        else
            $err = unparse_setting_error($info, "Invalid URL.");
    } else if ($info->type === "htmlstring") {
        if (($v = CleanHTML::basic_clean($v, $err)) === false)
            $err = unparse_setting_error($info, $err);
        else if ($info->message_default
                 && $v === $Conf->message_default_html($info->message_default))
            return "";
        else
            return $v;
    } else if ($info->type === "radio") {
        foreach ($info->values as $allowedv)
            if ((string) $allowedv === $v)
                return $allowedv;
        $err = unparse_setting_error($info, "Parse error (unexpected value).");
    } else
        return $v;

    $sv->set_error($name, $err);
    return null;
}

function truthy($x) {
    return !($x === null || $x === 0 || $x === false
             || $x === "" || $x === "0" || $x === "false");
}

function opt_yes_no_optional($name) {
    global $Opt;
    if (($x = get($Opt, $name)) === 1 || $x === true)
        return 1;
    if ($x === 2)
        return 2;
    return 0;
}

function account_value($sv, $si) {
    if ($si->internal)
        return;

    $xname = str_replace(".", "_", $si->name);
    if ($si->parser)
        $has_value = truthy(get($sv->req, "has_$xname"));
    else
        $has_value = isset($sv->req[$xname])
            || (($si->type === "cdate" || $si->type === "checkbox")
                && truthy(get($sv->req, "has_$xname")));
    if (!$has_value)
        return;

    if ($si->disabled || $si->novalue || !$si->type || $si->type === "none")
        /* ignore changes to disabled/novalue settings */;
    else if ($si->parser) {
        $p = $sv->parser($si);
        if ($p->parse($sv, $si))
            $sv->save_callbacks[$si->name] = $si;
    } else {
        $v = parse_value($sv, $si->name, $si);
        if ($v === null)
            return;
        if (is_int($v) && $v <= 0 && $si->type !== "radio" && $si->type !== "zint")
            $v = null;
        $sv->save($si->name, $v);
        if ($si->ifnonempty)
            $sv->save($si->ifnonempty, $v === null ? null : 1);
    }
}

function do_setting_update($sv) {
    global $Conf, $Group, $Me, $Now, $Opt, $OptOverride;
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
            $sv->set_error($dn1, unparse_setting_error(Si::get($dn1), "Must come before " . Si::get($dn2, "short_description") . "."));
            $sv->set_error($dn2);
        }
    if ($sv->has_savedv("sub_sub"))
        $sv->save("sub_update", $sv->savedv("sub_sub"));
    if (get($Opt, "defaultSiteContact")) {
        if ($sv->has_savedv("opt.contactName")
            && get($Opt, "contactName") === $sv->savedv("opt.contactName"))
            $sv->save("opt.contactName", null);
        if ($sv->has_savedv("opt.contactEmail")
            && get($Opt, "contactEmail") === $sv->savedv("opt.contactEmail"))
            $sv->save("opt.contactEmail", null);
    }
    if ($sv->has_savedv("resp_active") && $sv->savedv("resp_active"))
        foreach (explode(" ", $sv->newv("resp_rounds")) as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if ($sv->newv("resp_open$isuf") > $sv->newv("resp_done$isuf")) {
                $sv->set_error("resp_open$isuf", unparse_setting_error(Si::get("resp_open"), "Must come before " . Si::get("resp_done", "short_description") . "."));
                $sv->set_error("resp_done$isuf");
            }
        }

    // update 'papersub'
    if ($sv->has_savedv("pc_seeall")) {
        // see also conference.php
        if ($sv->savedv("pc_seeall") <= 0)
            $x = "timeSubmitted>0";
        else
            $x = "timeWithdrawn<=0";
        $num = Dbl::fetch_ivalue("select paperId from Paper where $x limit 1") ? 1 : 0;
        if ($num != $Conf->setting("papersub"))
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
    $changedn = [];
    if (!$sv->has_errors()
        && (count($sv->savedv) || count($sv->save_callbacks))) {
        $tables = "Settings write";
        foreach ($sv->need_lock as $t => $need)
            if ($need)
                $tables .= ", $t write";
        $Conf->qe("lock tables $tables");

        // load db settings, pre-crosscheck
        $dbsettings = array();
        $result = Dbl::qe("select name, value, data from Settings");
        while (($row = edb_row($result)))
            $dbsettings[$row[0]] = $row;
        Dbl::free($result);

        // apply settings
        foreach ($sv->save_callbacks as $si) {
            $p = $sv->parser($si);
            $p->save($sv, $si);
        }

        $dv = $aq = $av = array();
        foreach ($sv->savedv as $n => $v) {
            if (substr($n, 0, 4) === "opt." && $v !== null) {
                $okey = substr($n, 4);
                $oldv = (array_key_exists($okey, $OptOverride) ? $OptOverride[$okey] : get($Opt, $okey));
                $Opt[$okey] = ($v[1] === null ? $v[0] : $v[1]);
                if ($oldv === $Opt[$okey])
                    $v = null; // delete override value in database
                else if (!array_key_exists($okey, $OptOverride))
                    $OptOverride[$okey] = $oldv;
            }
            if ($v === null
                ? !isset($dbsettings[$n])
                : isset($dbsettings[$n]) && (int) $dbsettings[$n][1] === $v[0] && $dbsettings[$n][2] === $v[1])
                continue;
            $changedn[] = $n;
            if ($v !== null) {
                $aq[] = "(?, ?, ?)";
                array_push($av, $n, $v[0], $v[1]);
            } else
                $dv[] = $n;
        }
        if (count($dv)) {
            Dbl::qe_apply("delete from Settings where name?a", array($dv));
            //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query_apply("delete from Settings where name?a", array($dv))));
        }
        if (count($aq)) {
            Dbl::qe_apply("insert into Settings (name, value, data) values\n\t" . join(",\n\t", $aq) . "\n\ton duplicate key update value=values(value), data=values(data)", $av);
            //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query_apply("insert into Settings (name, value, data) values\n\t" . join(",\n\t", $aq) . "\n\ton duplicate key update value=values(value), data=values(data)", $av)));
        }

        $Conf->qe("unlock tables");
        if (count($changedn))
            $Me->log_activity("Updated settings " . join(", ", $changedn));
        $Conf->load_settings();

        // contactdb may need to hear about changes to shortName
        if ($sv->has_savedv("opt.shortName")
            && get($Opt, "contactdb_dsn") && ($cdb = Contact::contactdb()))
            Dbl::ql($cdb, "update Conferences set shortName=? where dbName=?", $Opt["shortName"], $Opt["dbName"]);
    }

    // update the review form in case it's changed
    ReviewForm::clear_cache();
    if (!$sv->has_errors()) {
        $Conf->save_session("settings_highlight", $sv->error_fields());
        if (count($changedn))
            $Conf->confirmMsg("Changes saved.");
        else
            $Conf->warnMsg("No changes.");
        $sv->report();
        redirectSelf();
    } else {
        SettingGroup::crosscheck($sv, $Group);
        $sv->report();
    }
}
if (isset($_REQUEST["update"]) && check_post())
    do_setting_update($Sv);
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();

if (!$Sv->warnings_reported) {
    SettingGroup::crosscheck($Sv, $Group);
    $Sv->report();
}

$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . SettingGroup::$all[$Group]->description . "</strong>", "settings", actionBar());
$Conf->echoScript(""); // clear out other script references
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
    echo "<div class='aa'>",
        Ht::submit("update", "Save changes", array("class" => "bb")),
        " &nbsp;", Ht::submit("cancel", "Cancel"), "</div>";
}

echo "<div class='aahc'>";
doActionArea(true);

echo "<div>";
$Sv->interesting_groups[$Group] = true;
SettingGroup::$all[$Group]->render($Sv);
echo "</div>";

doActionArea(false);
echo "</div></div></div></div></form>\n";

$Conf->footerScript("hiliter_children('#settingsform');jQuery('textarea').autogrow()");
$Conf->footer();
