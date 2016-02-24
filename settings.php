<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

$DateExplanation = "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2014-10-31 00:00 UTC-1100” <a href='http://php.net/manual/en/datetime.formats.php'>(more examples)</a>";

// setting information
class Si {
    public $name;
    public $short_description;
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

    const SI_VALUE = 1;
    const SI_DATA = 2;
    const SI_SLICE = 4;
    const SI_OPT = 8;

    static public $all = [];
    static private $type_storage = [
        "emailheader" => self::SI_DATA, "emailstring" => self::SI_DATA,
        "htmlstring" => self::SI_DATA, "simplestring" => self::SI_DATA,
        "string" => self::SI_DATA, "tag" => self::SI_DATA,
        "taglist" => self::SI_DATA, "urlstring" => self::SI_DATA
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
        foreach (["short_description", "type", "storage", "parser", "ifnonempty", "message_default", "placeholder", "invalid_value"] as $k)
            $this->store($name, $k, $j, $k, "is_string");
        foreach (["internal", "optional", "novalue", "disabled"] as $k)
            $this->store($name, $k, $j, $k, "is_bool");
        $this->store($name, "size", $j, "size", "is_int");
        $this->store($name, "values", $j, "values", "is_array");
        if (isset($j->default_value) && (is_int($j->default_value) || is_string($j->default_value)))
            $this->default_value = $j->default_value;

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
    }

    public function storage() {
        return $this->storage ? : $this->name;
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
                    foreach (expand_includes($ConfSitePATH, $si) as $f)
                        if (($x = file_get_contents($f)))
                            $info = self::read($info, $x, $f);
            }
        }

        foreach ($info as $k => $v) {
            if (isset($v["require"])) {
                foreach (expand_includes($ConfSitePATH, $v["require"]) as $f)
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

class SettingValues {
    private $errf = array();
    private $errmsg = array();
    private $warnmsg = array();
    public $warnings_reported = false;

    public $parsers = array();
    public $save_callbacks = array();
    public $need_lock = array();

    public $req = array();
    public $savedv = array();

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
    public function set_error($field, $html = false) {
        if ($field)
            $this->errf[$field] = 2;
        if ($html !== false)
            $this->errmsg[] = $html;
        return false;
    }
    public function set_warning($field, $html = false) {
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

    public function label($name, $html, $label_id = null) {
        $name1 = is_array($name) ? $name[0] : $name;
        foreach (is_array($name) ? $name : array($name) as $n)
            if ($this->has_error($n)) {
                $html = '<span class="setting_error">' . $html . '</span>';
                break;
            }
        if ($label_id !== false)
            $html = Ht::label($html, $label_id ? : $name1);
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

    public function inputv($name, $default_value = null) {
        if ($this->use_req())
            return get($this->req, $name, $default_value);
        else
            return $default_value;
    }

    private function si($name) {
        $si = Si::get($name);
        if (!$si)
            error_log(caller_landmark(2) . ": setting $name: missing information");
        return $si;
    }

    public function sv($name, $default_value = null) {
        global $Conf;
        if ($this->use_req())
            return get($this->req, $name, $default_value);
        else
            return $this->oldv($name, $default_value);
    }
    public function oldv($name, $default_value = null) {
        return $this->si_oldv($name, $this->si($name), $default_value);
    }
    public function has_savedv($name) {
        $si = $this->si($name);
        return array_key_exists($si->storage(), $this->savedv);
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
            return $this->si_oldv($name, $si, $default_value);
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

    private function si_oldv($name, $si, $default_value) {
        global $Conf, $Opt;
        if ($si->storage_type & Si::SI_OPT)
            $val = get($Opt, substr($name, 4), $default_value);
        else if ($si->storage_type & Si::SI_DATA)
            $val = $Conf->setting_data($name, $default_value);
        else
            $val = $Conf->setting($name, $default_value);
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
        $x = $this->sv($name);
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, array("onchange" => $onchange, "id" => "cb$name"))),
            "&nbsp;",
            $this->label($name, $text, true),
            "<br />\n";
    }

    public function echo_checkbox_row($name, $text, $onchange = null) {
        $x = $this->sv($name);
        echo '<tr><td class="nw">',
            Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, array("onchange" => $onchange, "id" => "cb$name"))),
            "&nbsp;</td><td>",
            $this->label($name, $text, true),
            "</td></tr>\n";
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
    private $render;

    static public $all;
    static public $map;
    static private $sorted = false;

    public function __construct($name, $description, $priority, $renderer) {
        $this->name = $name;
        $this->description = $description;
        $this->priority = $priority;
        $this->render = [[0, 0, $renderer]];
    }
    public function add_renderer($priority, $renderer) {
        $x = [$priority, count($this->render), $renderer];
        $this->render[] = $x;
    }
    public function render($sv) {
        usort($this->render, function ($a, $b) {
            if ($a[0] != $b[0])
                return $a[0] < $b[0] ? -1 : 1;
            if ($a[1] != $b[1])
                return $a[1] < $b[1] ? -1 : 1;
            return 0;
        });
        foreach ($this->render as $r)
            call_user_func($r[2], $sv);
    }

    static public function register($name, $description, $priority, $renderer) {
        assert(!isset(self::$all[$name]) && !isset(self::$map[$name]));
        self::$all[$name] = new SettingGroup($name, $description, $priority, $renderer);
        self::$sorted = false;
    }
    static public function register_renderer($name, $priority, $renderer) {
        assert(isset(self::$all[$name]));
        self::$all[$name]->add_renderer($priority, $renderer);
    }
    static public function register_synonym($new_name, $old_name) {
        assert(isset(self::$all[$old_name]) && !isset(self::$map[$old_name]));
        assert(!isset(self::$all[$new_name]) && !isset(self::$map[$new_name]));
        self::$map[$new_name] = $old_name;
    }
    static public function all() {
        if (!self::$sorted) {
            uasort(self::$all, function ($a, $b) {
                if ($a->priority != $b->priority)
                    return $a->priority < $b->priority ? -1 : 1;
                else
                    return strcasecmp($a->name, $b->name);
            });
            self::$sorted = true;
        }
        return self::$all;
    }
}

SettingGroup::register("basics", "Basics", 0, "doInfoGroup");
SettingGroup::register_synonym("info", "basics");
SettingGroup::register("users", "Accounts", 100, "doAccGroup");
SettingGroup::register_synonym("acc", "users");
SettingGroup::register("msg", "Messages", 200, "doMsgGroup");
SettingGroup::register("sub", "Submissions", 300, "doSubGroup");
SettingGroup::register("subform", "Submission form", 400, "doOptGroup");
SettingGroup::register_synonym("opt", "subform");
SettingGroup::register("reviews", "Reviews", 500, "doRevGroup");
SettingGroup::register_synonym("rev", "reviews");
SettingGroup::register_synonym("review", "reviews");
SettingGroup::register("tags", "Tags &amp; tracks", 700, "doTagsGroup");
SettingGroup::register_synonym("tracks", "tags");
SettingGroup::register("decisions", "Decisions", 800, "doDecGroup");
SettingGroup::register_synonym("dec", "decisions");

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

function unparseGrace($v) {
    if ($v === null || $v <= 0 || !is_numeric($v))
        return "none";
    if ($v % 3600 == 0)
        return ($v / 3600) . " hr";
    if ($v % 60 == 0)
        return ($v / 60) . " min";
    return sprintf("%d:%02d", intval($v / 60), $v % 60);
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
    global $Conf, $Now, $Opt;

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
    } else if ($info->type === "emailheader") {
        $v = MimeText::encode_email_header("", $v);
        if ($v !== false)
            return ($v == "" ? "" : MimeText::decode_header($v));
        else
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
        if (($v = CleanHTML::clean($v, $err)) === false)
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

class Tag_SettingParser extends SettingParser {
    private $tagger;
    public function __construct() {
        $this->tagger = new Tagger;
    }
    private function parse_list($sv, $si, $checkf, $min_idx) {
        $ts = array();
        foreach (preg_split('/\s+/', $sv->req[$si->name]) as $t)
            if ($t !== "" && $this->tagger->check($t, $checkf)) {
                list($tag, $idx) = TagInfo::split_index($t);
                if ($min_idx)
                    $t = $tag . "#" . max($min_idx, (float) $idx);
                $ts[$tag] = $t;
            } else if ($t !== "")
                $sv->set_error($si->name, $si->short_description . ": " . $this->tagger->error_html);
        return array_values($ts);
    }
    public function parse($sv, $si) {
        if ($si->name == "tag_chair" && isset($sv->req["tag_chair"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_vote" && isset($sv->req["tag_vote"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR, 1);
            if ($sv->update("tag_vote", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_approval" && isset($sv->req["tag_approval"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if ($sv->update("tag_approval", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_rank" && isset($sv->req["tag_rank"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (count($ts) > 1)
                $sv->set_error("tag_rank", "At most one rank tag is currently supported.");
            else
                $sv->update("tag_rank", join(" ", $ts));
        }

        if ($si->name == "tag_color") {
            $ts = array();
            $any_set = false;
            foreach (explode("|", TagInfo::BASIC_COLORS) as $k)
                if (isset($sv->req["tag_color_$k"])) {
                    $xsi = new Si("tag_color_$k", ["name" => ucfirst($k) . " style tag"]);
                    $any_set = true;
                    foreach ($this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            if ($any_set)
                $sv->update("tag_color", join(" ", $ts));
        }

        if ($si->name == "tag_badge") {
            $ts = array();
            $any_set = false;
            foreach (explode("|", TagInfo::BASIC_BADGES) as $k)
                if (isset($sv->req["tag_badge_$k"])) {
                    $xsi = new Si("tag_badge_$k", ["name" => ucfirst($k) . " badge style tag"]);
                    $any_set = true;
                    foreach ($this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            if ($any_set)
                $sv->update("tag_badge", join(" ", $ts));
        }

        if ($si->name == "tag_au_seerev" && isset($sv->req["tag_au_seerev"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $chair_tags = array_flip(explode(" ", $sv->newv("tag_chair")));
            foreach ($ts as $t)
                if (!isset($chair_tags[$t]))
                    $sv->set_warning("tag_au_seerev", "Review visibility tag “" . htmlspecialchars($t) . "” isn’t a <a href=\"" . hoturl("settings", "group=tags") . "\">chair-only tag</a>, which means PC members can change it. You usually want these tags under chair control.");
            $sv->update("tag_au_seerev", join(" ", $ts));
        }

        return true;
    }

    public function save($sv, $si) {
        if ($si->name == "tag_vote" && $sv->has_savedv("tag_vote")) {
            // check allotments
            $pcm = pcMembers();
            foreach (preg_split('/\s+/', $sv->savedv("tag_vote")) as $t) {
                if ($t === "")
                    continue;
                $base = substr($t, 0, strpos($t, "#"));
                $allotment = substr($t, strlen($base) + 1);

                $result = $Conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($base) . "'");
                $pvals = array();
                $cvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->set_error(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$base}” vote for paper #$row[0].");
                        $negative = true;
                    } else {
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + $row[2];
                        $cvals[$who] = defval($cvals, $who, 0) + $row[2];
                    }
                }

                foreach ($cvals as $who => $what)
                    if ($what > $allotment)
                        $sv->set_error("tag_vote", Text::user_html($pcm[$who]) . " already has more than $allotment votes for tag “{$base}”.");

                $q = ($negative ? " or (tag like '%~" . sqlq_for_like($base) . "' and tagIndex<0)" : "");
                $Conf->qe("delete from PaperTag where tag='" . sqlq($base) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($base) . "', $what)";
                if (count($q) > 0)
                    $Conf->qe("insert into PaperTag values " . join(", ", $q));
            }
        }

        if ($si->name == "tag_approval" && $sv->has_savedv("tag_approval")) {
            $pcm = pcMembers();
            foreach (preg_split('/\s+/', $sv->savedv("tag_approval")) as $t) {
                if ($t === "")
                    continue;
                $result = $Conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($t) . "'");
                $pvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->set_error(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$t}” approval vote for paper #$row[0].");
                        $negative = true;
                    } else
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + 1;
                }

                $q = ($negative ? " or (tag like '%~" . sqlq_for_like($t) . "' and tagIndex<0)" : "");
                $Conf->qe("delete from PaperTag where tag='" . sqlq($t) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($t) . "', $what)";
                if (count($q) > 0)
                    $Conf->qe("insert into PaperTag values " . join(", ", $q));
            }
        }

        TagInfo::invalidate_defined_tags();
    }
}

class Topic_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        foreach (["TopicArea", "PaperTopic", "TopicInterest"] as $t)
            $sv->need_lock[$t] = true;
        return true;
    }

    public function save($sv, $si) {
        global $Conf;
        $tmap = $Conf->topic_map();
        foreach ($sv->req as $k => $v)
            if ($k === "topnew") {
                $news = array();
                foreach (explode("\n", $v) as $n)
                    if (($n = simplify_whitespace($n)) !== "")
                        $news[] = "('" . sqlq($n) . "')";
                if (count($news))
                    $Conf->qe("insert into TopicArea (topicName) values " . join(",", $news));
            } else if (strlen($k) > 3 && substr($k, 0, 3) === "top"
                       && ctype_digit(substr($k, 3))) {
                $k = (int) substr($k, 3);
                $v = simplify_whitespace($v);
                if ($v == "") {
                    $Conf->qe("delete from TopicArea where topicId=$k");
                    $Conf->qe("delete from PaperTopic where topicId=$k");
                    $Conf->qe("delete from TopicInterest where topicId=$k");
                } else if (isset($tmap[$k]) && $v != $tmap[$k] && !ctype_digit($v))
                    $Conf->qe("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k");
            }
        $Conf->invalidate_topics();
    }
}


function option_request_to_json($sv, &$new_opts, $id, $current_opts) {
    global $Conf;

    $name = simplify_whitespace(defval($sv->req, "optn$id", ""));
    if (!isset($sv->req["optn$id"]) && $id[0] !== "n") {
        if (get($current_opts, $id))
            $new_opts[$id] = $current_opts[$id];
        return;
    } else if ($name === ""
               || $sv->req["optfp$id"] === "delete"
               || ($id[0] === "n" && ($name === "New option" || $name === "(Enter new option)")))
        return;

    $oarg = ["name" => $name, "id" => (int) $id, "final" => false];
    if ($id[0] === "n") {
        $nextid = max($Conf->setting("next_optionid", 1), 1);
        foreach ($new_opts as $haveid => $o)
            $nextid = max($nextid, $haveid + 1);
        foreach ($current_opts as $haveid => $o)
            $nextid = max($nextid, $haveid + 1);
        $oarg["id"] = $nextid;
    }

    if (get($sv->req, "optd$id") && trim($sv->req["optd$id"]) != "") {
        $t = CleanHTML::clean($sv->req["optd$id"], $err);
        if ($t !== false)
            $oarg["description"] = $t;
        else
            $sv->set_error("optd$id", $err);
    }

    if (($optvt = get($sv->req, "optvt$id"))) {
        if (($pos = strpos($optvt, ":")) !== false) {
            $oarg["type"] = substr($optvt, 0, $pos);
            if (preg_match('/:final/', $optvt))
                $oarg["final"] = true;
            if (preg_match('/:ds_(\d+)/', $optvt, $m))
                $oarg["display_space"] = (int) $m[1];
        } else
            $oarg["type"] = $optvt;
    } else
        $oarg["type"] = "checkbox";

    if (PaperOption::type_has_selector($oarg["type"])) {
        $oarg["selector"] = array();
        $seltext = trim(cleannl(defval($sv->req, "optv$id", "")));
        if ($seltext != "") {
            foreach (explode("\n", $seltext) as $t)
                $oarg["selector"][] = $t;
        } else
            $sv->set_error("optv$id", "Enter selectors one per line.");
    }

    $oarg["visibility"] = defval($sv->req, "optp$id", "rev");
    if ($oarg["final"])
        $oarg["visibility"] = "rev";

    $oarg["position"] = (int) defval($sv->req, "optfp$id", 1);

    $oarg["display"] = defval($sv->req, "optdt$id");
    if ($oarg["type"] === "pdf" && $oarg["final"])
        $oarg["display"] = "submission";

    $new_opts[$oarg["id"]] = $o = PaperOption::make($oarg);
    $o->req_id = $id;
    $o->is_new = $id[0] === "n";
}

function option_clean_form_positions($new_opts, $current_opts) {
    foreach ($new_opts as $id => $o) {
        $current_o = get($current_opts, $id);
        $o->old_position = ($current_o ? $current_o->position : $o->position);
        $o->position_set = false;
    }
    for ($i = 0; $i < count($new_opts); ++$i) {
        $best = null;
        foreach ($new_opts as $id => $o)
            if (!$o->position_set
                && (!$best
                    || ($o->display() === PaperOption::DISP_SUBMISSION
                        && $best->display() !== PaperOption::DISP_SUBMISSION)
                    || $o->position < $best->position
                    || ($o->position == $best->position
                        && $o->position != $o->old_position
                        && $best->position == $best->old_position)
                    || ($o->position == $best->position
                        && strcasecmp($o->name, $best->name) < 0)
                    || ($o->position == $best->position
                        && strcasecmp($o->name, $best->name) == 0
                        && strcmp($o->name, $best->name) < 0)))
                $best = $o;
        $best->position = $i + 1;
        $best->position_set = true;
    }
}

class Option_SettingParser extends SettingParser {
    private $stashed_options = false;

    function parse($sv, $si) {
        $current_opts = PaperOption::option_list();

        // convert request to JSON
        $new_opts = array();
        foreach ($current_opts as $id => $o)
            option_request_to_json($sv, $new_opts, $id, $current_opts);
        foreach ($sv->req as $k => $v)
            if (substr($k, 0, 4) == "optn"
                && !get($current_opts, substr($k, 4)))
                option_request_to_json($sv, $new_opts, substr($k, 4), $current_opts);

        // check abbreviations
        $optabbrs = array();
        foreach ($new_opts as $id => $o)
            if (preg_match('/\Aopt\d+\z/', $o->abbr))
                $sv->set_error("optn$o->req_id", "Option name “" . htmlspecialchars($o->name) . "” is reserved. Please pick another option name.");
            else if (get($optabbrs, $o->abbr))
                $sv->set_error("optn$o->req_id", "Multiple options abbreviate to “{$o->abbr}”. Please pick option names that abbreviate uniquely.");
            else
                $optabbrs[$o->abbr] = $o;

        if (!$sv->has_errors()) {
            $this->stashed_options = $new_opts;
            $sv->need_lock["PaperOption"] = true;
            return true;
        }
    }

    public function save($sv, $si) {
        global $Conf;
        $new_opts = $this->stashed_options;
        $current_opts = PaperOption::option_list();
        option_clean_form_positions($new_opts, $current_opts);

        $newj = (object) array();
        uasort($new_opts, array("PaperOption", "compare"));
        $nextid = max($Conf->setting("next_optionid", 1), $Conf->setting("options", 1));
        foreach ($new_opts as $id => $o) {
            $newj->$id = $o->unparse();
            $nextid = max($nextid, $id + 1);
        }
        $sv->save("next_optionid", null);
        $sv->save("options", count($newj) ? json_encode($newj) : null);

        // warn on visibility
        if ($sv->newv("sub_blind") === Conf::BLIND_ALWAYS) {
            foreach ($new_opts as $id => $o)
                if ($o->visibility === "nonblind")
                    $sv->set_warning("optp$id", "The “" . htmlspecialchars($o->name) . "” option is marked as “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }

        $deleted_ids = array();
        foreach ($current_opts as $id => $o)
            if (!get($new_opts, $id))
                $deleted_ids[] = $id;
        if (count($deleted_ids))
            $Conf->qe("delete from PaperOption where optionId in (" . join(",", $deleted_ids) . ")");

        // invalidate cached option list
        PaperOption::invalidate_option_list();
    }
}

class Decision_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        $dec_revmap = array();
        foreach ($sv->req as $k => &$dname)
            if (str_starts_with($k, "dec")
                && ($k === "decn" || ($dnum = cvtint(substr($k, 3), 0)))
                && ($k !== "decn" || trim($dname) !== "")) {
                $dname = simplify_whitespace($dname);
                if ($dname === "")
                    /* remove decision */;
                else if (($derror = Conf::decision_name_error($dname)))
                    $sv->set_error($k, htmlspecialchars($derror));
                else if (isset($dec_revmap[strtolower($dname)]))
                    $sv->set_error($k, "Decision name “{$dname}” was already used.");
                else
                    $dec_revmap[strtolower($dname)] = true;
            }
        unset($dname);

        if (get($sv->req, "decn") && !get($sv->req, "decn_confirm")) {
            $delta = (defval($sv->req, "dtypn", 1) > 0 ? 1 : -1);
            $match_accept = (stripos($sv->req["decn"], "accept") !== false);
            $match_reject = (stripos($sv->req["decn"], "reject") !== false);
            if ($delta > 0 && $match_reject)
                $sv->set_error("decn", "You are trying to add an Accept-class decision that has “reject” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
            else if ($delta < 0 && $match_accept)
                $sv->set_error("decn", "You are trying to add a Reject-class decision that has “accept” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
        }

        $sv->need_lock["Paper"] = true;
        return true;
    }

    public function save($sv, $si) {
        global $Conf;
        // mark all used decisions
        $decs = $Conf->decision_map();
        $update = false;
        foreach ($sv->req as $k => $v)
            if (str_starts_with($k, "dec") && ($k = cvtint(substr($k, 3), 0))) {
                if ($v == "") {
                    $Conf->qe("update Paper set outcome=0 where outcome=$k");
                    unset($decs[$k]);
                    $update = true;
                } else if ($v != $decs[$k]) {
                    $decs[$k] = $v;
                    $update = true;
                }
            }

        if (defval($sv->req, "decn", "") != "") {
            $delta = (defval($sv->req, "dtypn", 1) > 0 ? 1 : -1);
            for ($k = $delta; isset($decs[$k]); $k += $delta)
                /* skip */;
            $decs[$k] = $sv->req["decn"];
            $update = true;
        }

        if ($update)
            $sv->save("outcome_map", json_encode($decs));
    }
}

class Banal_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        global $Conf, $ConfSitePATH;
        if (!isset($sv->req["sub_banal"])) {
            $sv->save("sub_banal", 0);
            return false;
        }

        // check banal subsettings
        $old_error_count = $sv->error_count();
        $bs = array_fill(0, 6, "");
        if (($s = trim(defval($sv->req, "sub_banal_papersize", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            $ses = preg_split('/\s*,\s*|\s+OR\s+/i', $s);
            $sout = array();
            foreach ($ses as $ss)
                if ($ss != "" && CheckFormat::parse_dimen($ss, 2))
                    $sout[] = $ss;
                else if ($ss != "") {
                    $sv->set_error("sub_banal_papersize", "Invalid paper size.");
                    $sout = null;
                    break;
                }
            if ($sout && count($sout))
                $bs[0] = join(" OR ", $sout);
        }

        if (($s = trim(defval($sv->req, "sub_banal_pagelimit", ""))) != ""
            && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) > 0)
                $bs[1] = $sx;
            else if (preg_match('/\A(\d+)\s*-\s*(\d+)\z/', $s, $m)
                     && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
                $bs[1] = +$m[1] . "-" . +$m[2];
            else
                $sv->set_error("sub_banal_pagelimit", "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.");
        }

        if (($s = trim(defval($sv->req, "sub_banal_columns", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (($sx = cvtint($s, -1)) >= 0)
                $bs[2] = ($sx > 0 ? $sx : $bs[2]);
            else
                $sv->set_error("sub_banal_columns", "Columns must be a whole number.");
        }

        if (($s = trim(defval($sv->req, "sub_banal_textblock", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            // change margin specifications into text block measurements
            if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
                $s = $m[1];
                if (!($ps = CheckFormat::parse_dimen($bs[0]))) {
                    $sv->set_error("sub_banal_pagesize", "You must specify a page size as well as margins.");
                    $sv->set_error("sub_banal_textblock");
                } else if (strpos($s, "x") !== false) {
                    if (!($m = CheckFormat::parse_dimen($s)) || !is_array($m) || count($m) > 4) {
                        $sv->set_error("sub_banal_textblock", "Invalid margin definition.");
                        $s = "";
                    } else if (count($m) == 2)
                        $s = array($ps[0] - 2 * $m[0], $ps[1] - 2 * $m[1]);
                    else if (count($m) == 3)
                        $s = array($ps[0] - 2 * $m[0], $ps[1] - $m[1] - $m[2]);
                    else
                        $s = array($ps[0] - $m[0] - $m[2], $ps[1] - $m[1] - $m[3]);
                } else {
                    $s = preg_replace('/\s+/', 'x', $s);
                    if (!($m = CheckFormat::parse_dimen($s)) || (is_array($m) && count($m) > 4))
                        $sv->set_error("sub_banal_textblock", "Invalid margin definition.");
                    else if (!is_array($m))
                        $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
                    else if (count($m) == 2)
                        $s = array($ps[0] - 2 * $m[1], $ps[1] - 2 * $m[0]);
                    else if (count($m) == 3)
                        $s = array($ps[0] - 2 * $m[1], $ps[1] - $m[0] - $m[2]);
                    else
                        $s = array($ps[0] - $m[1] - $m[3], $ps[1] - $m[0] - $m[2]);
                }
                $s = (is_array($s) ? CheckFormat::unparse_dimen($s) : "");
            }
            // check text block measurements
            if ($s && !CheckFormat::parse_dimen($s, 2))
                $sv->set_error("sub_banal_textblock", "Invalid text block definition.");
            else if ($s)
                $bs[3] = $s;
        }

        if (($s = trim(defval($sv->req, "sub_banal_bodyfontsize", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (!is_numeric($s) || $s <= 0)
                $sv->error("sub_banal_bodyfontsize", "Minimum body font size must be a number bigger than 0.");
            else
                $bs[4] = $s;
        }

        if (($s = trim(defval($sv->req, "sub_banal_bodyleading", ""))) != ""
            && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
            if (!is_numeric($s) || $s <= 0)
                $sv->error("sub_banal_bodyleading", "Minimum body leading must be a number bigger than 0.");
            else
                $bs[5] = $s;
        }

        if ($sv->error_count() != $old_error_count)
            return false;

        // Perhaps we have an old pdftohtml with a bad -zoom.
        $zoomarg = "";
        for ($tries = 0; $tries < 2; ++$tries) {
            $cf = new CheckFormat();
            $s1 = $cf->analyzeFile("$ConfSitePATH/src/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
            $e1 = $cf->errors;
            if ($s1 == 1 && ($e1 & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
                $zoomarg = ">-zoom=1";
            else if ($s1 != 2 && $tries == 1)
                $zoomarg = "";
        }

        // actually create setting
        while (count($bs) > 0 && $bs[count($bs) - 1] == "")
            array_pop($bs);
        $sv->save("sub_banal_data", join(";", $bs) . $zoomarg);
        $e1 = $cf->errors;
        $s2 = $cf->analyzeFile("$ConfSitePATH/src/sample.pdf", "a4;1;;3inx3in;14;15" . $zoomarg);
        $e2 = $cf->errors;
        $want_e2 = CheckFormat::ERR_PAPERSIZE | CheckFormat::ERR_PAGELIMIT
            | CheckFormat::ERR_TEXTBLOCK | CheckFormat::ERR_BODYFONTSIZE
            | CheckFormat::ERR_BODYLEADING;
        if ($s1 != 2 || $e1 != 0 || $s2 != 1 || ($e2 & $want_e2) != $want_e2) {
            $errors = "<div class=\"fx\"><table><tr><td>Analysis:&nbsp;</td><td>$s1 $e1 $s2 $e2 (expected 2 0 1 $want_e2)</td></tr>"
                . "<tr><td>Exit status:&nbsp;</td><td>" . htmlspecialchars($cf->banal_status) . "</td></tr>";
            if (trim($cf->banal_stdout))
                $errors .= "<tr><td>Stdout:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stdout) . "</pre></td></tr>";            if (trim($cf->banal_stdout))
            if (trim($cf->banal_stderr))
                $errors .= "<tr><td>Stderr:&nbsp;</td><td><pre class=\"email\">" . htmlspecialchars($cf->banal_stderr) . "</pre></td></tr>";
            $sv->set_warning(null, "Running the automated paper checker on a sample PDF file produced unexpected results. You should disable it for now. <div id=\"foldbanal_warning\" class=\"foldc\">" . foldbutton("banal_warning", 0, "Checker output") . $errors . "</table></div></div>");
        }

        return false;
    }
}

class Track_SettingParser extends SettingParser {
    public function parse($sv, $si) {
        $tagger = new Tagger;
        $tracks = (object) array();
        $missing_tags = false;
        for ($i = 1; isset($sv->req["name_track$i"]); ++$i) {
            $trackname = trim($sv->req["name_track$i"]);
            if ($trackname === "" || $trackname === "(tag)")
                continue;
            else if (!$tagger->check($trackname, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)
                     || ($trackname === "_" && $i != 1)) {
                if ($trackname !== "_")
                    $sv->set_error("name_track$i", "Track name: " . $tagger->error_html);
                else
                    $sv->set_error("name_track$i", "Track name “_” is reserved.");
                $sv->set_error("tracks");
                continue;
            }
            $t = (object) array();
            foreach (Track::$map as $type => $value)
                if (($ttype = defval($sv->req, "${type}_track$i", "")) == "+"
                    || $ttype == "-") {
                    $ttag = trim(defval($sv->req, "${type}tag_track$i", ""));
                    if ($ttag === "" || $ttag === "(tag)") {
                        $sv->set_error("{$type}_track$i", "Tag missing for track setting.");
                        $sv->set_error("tracks");
                    } else if (($ttype == "+" && strcasecmp($ttag, "none") == 0)
                               || $tagger->check($ttag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $t->$type = $ttype . $ttag;
                    else {
                        $sv->set_error("{$type}_track$i", $tagger->error_html);
                        $sv->set_error("tracks");
                    }
                } else if ($ttype == "none")
                    $t->$type = "+none";
            if (count((array) $t) || get($tracks, "_"))
                $tracks->$trackname = $t;
            if (get($t, "viewpdf") && $t->viewpdf != get($t, "unassrev")
                && get($t, "unassrev") != "+none")
                $sv->set_warning(null, ($trackname === "_" ? "Default track" : "Track “{$trackname}”") . ": Generally, a track that restricts PDF visibility should restrict the “self-assign papers” right in the same way.");
        }
        $sv->save("tracks", count((array) $tracks) ? json_encode($tracks) : null);
        return false;
    }
}

class Round_SettingParser extends SettingParser {
    private $rev_round_changes = array();

    function parse($sv, $si) {
        global $Conf;
        if (!isset($sv->req["rev_roundtag"])) {
            $sv->save("rev_roundtag", null);
            return false;
        }
        // round names
        $roundnames = $roundnames_set = array();
        $roundname0 = $round_deleted = null;
        for ($i = 0;
             isset($sv->req["roundname_$i"]) || isset($sv->req["deleteround_$i"]) || !$i;
             ++$i) {
            $rname = trim(get_s($sv->req, "roundname_$i"));
            if ($rname === "(no name)" || $rname === "default" || $rname === "unnamed")
                $rname = "";
            if ((get($sv->req, "deleteround_$i") || $rname === "") && $i) {
                $roundnames[] = ";";
                $this->rev_round_changes[] = array($i, 0);
                if ($round_deleted === null && !isset($sv->req["roundname_0"])
                    && $i < $sv->req["oldroundcount"])
                    $round_deleted = $i;
            } else if ($rname === "")
                /* ignore */;
            else if (($rerror = Conf::round_name_error($rname)))
                $sv->set_error("roundname_$i", $rerror);
            else if ($i == 0)
                $roundname0 = $rname;
            else if (get($roundnames_set, strtolower($rname))) {
                $roundnames[] = ";";
                $this->rev_round_changes[] = array($i, $roundnames_set[strtolower($rname)]);
            } else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }
        if ($roundname0 && !get($roundnames_set, strtolower($roundname0))) {
            $roundnames[] = $roundname0;
            $roundnames_set[strtolower($roundname0)] = count($roundnames);
        }
        if ($roundname0)
            array_unshift($this->rev_round_changes, array(0, $roundnames_set[strtolower($roundname0)]));

        // round deadlines
        foreach ($Conf->round_list() as $i => $rname) {
            $suffix = $i ? "_$i" : "";
            foreach (Conf::$review_deadlines as $k)
                $sv->save($k . $suffix, null);
        }
        $rtransform = array();
        if ($roundname0 && ($ri = $roundnames_set[strtolower($roundname0)])
            && !isset($sv->req["pcrev_soft_$ri"])) {
            $rtransform[0] = "_$ri";
            $rtransform[$ri] = false;
        }
        if ($round_deleted) {
            $rtransform[$round_deleted] = "";
            if (!isset($rtransform[0]))
                $rtransform[0] = false;
        }
        for ($i = 0; $i < count($roundnames) + 1; ++$i)
            if ((isset($rtransform[$i])
                 || ($i ? $roundnames[$i - 1] !== ";" : !isset($sv->req["deleteround_0"])))
                && get($rtransform, $i) !== false) {
                $isuffix = $i ? "_$i" : "";
                if (($osuffix = get($rtransform, $i)) === null)
                    $osuffix = $isuffix;
                $ndeadlines = 0;
                foreach (Conf::$review_deadlines as $k) {
                    $v = parse_value($sv, $k . $isuffix, Si::get($k));
                    $sv->save($k . $osuffix, $v <= 0 ? null : $v);
                    $ndeadlines += $v > 0;
                }
                if ($ndeadlines == 0 && $osuffix)
                    $sv->save("pcrev_soft$osuffix", 0);
                foreach (array("pcrev_", "extrev_") as $k) {
                    list($soft, $hard) = ["{$k}soft$osuffix", "{$k}hard$osuffix"];
                    list($softv, $hardv) = [$sv->savedv($soft), $sv->savedv($hard)];
                    if (!$softv && $hardv)
                        $sv->save($soft, $hardv);
                    else if ($hardv && $softv > $hardv) {
                        $desc = $i ? ", round " . htmlspecialchars($roundnames[$i - 1]) : "";
                        $sv->set_error($soft, Si::get("{$k}soft", "short_description") . $desc . ": Must come before " . Si::get("{$k}hard", "short_description") . ".");
                        $sv->set_error($hard);
                    }
                }
            }

        // round list (save after deadlines processing)
        while (count($roundnames) && $roundnames[count($roundnames) - 1] === ";")
            array_pop($roundnames);
        $sv->save("tag_rounds", join(" ", $roundnames));

        // default round
        $t = trim($sv->req["rev_roundtag"]);
        $sv->save("rev_roundtag", null);
        if (preg_match('/\A(?:|\(none\)|\(no name\)|default|unnamed)\z/i', $t))
            /* do nothing */;
        else if ($t === "#0") {
            if ($roundname0)
                $sv->save("rev_roundtag", $roundname0);
        } else if (preg_match('/^#[1-9][0-9]*$/', $t)) {
            $rname = get($roundnames, substr($t, 1) - 1);
            if ($rname && $rname !== ";")
                $sv->save("rev_roundtag", $rname);
        } else if (!($rerror = Conf::round_name_error($t)))
            $sv->save("rev_roundtag", $t);
        else
            $sv->set_error("rev_roundtag", $rerror);
        if (count($this->rev_round_changes)) {
            $sv->need_lock["PaperReview"] = true;
            return true;
        } else
            return false;
    }
    public function save($sv, $si) {
        global $Conf;
        // remove references to deleted rounds
        foreach ($this->rev_round_changes as $x)
            $Conf->qe("update PaperReview set reviewRound=$x[1] where reviewRound=$x[0]");
    }
}

class RespRound_SettingParser extends SettingParser {
    function parse($sv, $si) {
        global $Conf;
        if (!$sv->newv("resp_active"))
            return false;
        $old_roundnames = $Conf->resp_round_list();
        $roundnames = array(1);
        $roundnames_set = array();

        if (isset($sv->req["resp_roundname"])) {
            $rname = trim(get_s($sv->req, "resp_roundname"));
            if ($rname === "" || $rname === "none" || $rname === "1")
                /* do nothing */;
            else if (($rerror = Conf::resp_round_name_error($rname)))
                $sv->set_error("resp_roundname", $rerror);
            else {
                $roundnames[0] = $rname;
                $roundnames_set[strtolower($rname)] = 0;
            }
        }

        for ($i = 1; isset($sv->req["resp_roundname_$i"]); ++$i) {
            $rname = trim(get_s($sv->req, "resp_roundname_$i"));
            if ($rname === "" && get($old_roundnames, $i))
                $rname = $old_roundnames[$i];
            if ($rname === "")
                continue;
            else if (($rerror = Conf::resp_round_name_error($rname)))
                $sv->set_error("resp_roundname_$i", $rerror);
            else if (get($roundnames_set, strtolower($rname)) !== null)
                $sv->set_error("resp_roundname_$i", "Response round name “" . htmlspecialchars($rname) . "” has already been used.");
            else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }

        foreach ($roundnames_set as $i) {
            $isuf = $i ? "_$i" : "";
            if (($v = parse_value($sv, "resp_open$isuf", Si::get("resp_open"))) !== null)
                $sv->save("resp_open$isuf", $v <= 0 ? null : $v);
            if (($v = parse_value($sv, "resp_done$isuf", Si::get("resp_done"))) !== null)
                $sv->save("resp_done$isuf", $v <= 0 ? null : $v);
            if (($v = parse_value($sv, "resp_words$isuf", Si::get("resp_words"))) !== null)
                $sv->save("resp_words$isuf", $v < 0 ? null : $v);
            if (($v = parse_value($sv, "msg.resp_instrux$isuf", Si::get("msg.resp_instrux"))) !== null)
                $sv->save("msg.resp_instrux$isuf", $v);
        }

        if (count($roundnames) > 1 || $roundnames[0] !== 1)
            $sv->save("resp_rounds", join(" ", $roundnames));
        else
            $sv->save("resp_rounds", null);
        return false;
    }
}

function truthy($x) {
    return !($x === null || $x === 0 || $x === false
             || $x === "" || $x === "0" || $x === "false");
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
        if (!isset($sv->parsers[$si->parser]))
            $sv->parsers[$si->parser] = new $si->parser;
        if ($sv->parsers[$si->parser]->parse($sv, $si))
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

function setting_warnings($sv, $group) {
    global $Conf, $Now;
    if (($sv->has_savedv("sub_open") || !$group || $group === "sub")
        && $sv->newv("sub_freeze", -1) == 0
        && $sv->newv("sub_open") > 0
        && $sv->newv("sub_sub") <= 0)
        $sv->set_warning(null, "Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You probably should (1) specify a paper submission deadline; (2) select “Authors must freeze the final version of each submission”; or (3) manually turn off “Open site for submissions” when submissions complete.");
    $errored = false;
    foreach ($Conf->round_list() as $i => $rname) {
        $suffix = $i ? "_$i" : "";
        foreach (Conf::$review_deadlines as $deadline)
            if (($sv->has_savedv($deadline . $suffix) || !$group || $group === "reviews")
                && $sv->newv($deadline . $suffix) > $Now
                && $sv->newv("rev_open") <= 0
                && !$errored) {
                $sv->set_warning("rev_open", "A review deadline is set in the future, but the site is not open for reviewing. This is sometimes unintentional.");
                $errored = true;
                break;
            }
    }
    if (($sv->has_savedv("au_seerev") || !$group || $group === "reviews" || $group === "decisions")
        && $sv->newv("au_seerev") != Conf::AUSEEREV_NO
        && $sv->newv("au_seerev") != Conf::AUSEEREV_TAGS
        && $sv->oldv("pcrev_soft") > 0
        && $Now < $sv->oldv("pcrev_soft")
        && !$sv->has_errors())
        $sv->set_warning(null, "Authors can see reviews and comments although it is before the review deadline. This is sometimes unintentional.");
    if (($sv->has_savedv("final_open") || !$group || $group === "decisions")
        && $sv->newv("final_open")
        && ($sv->newv("final_soft") || $sv->newv("final_done"))
        && (!$sv->newv("final_done") || $sv->newv("final_done") > $Now)
        && $sv->newv("seedec") != Conf::SEEDEC_ALL)
        $sv->set_warning(null, "The system is set to collect final versions, but authors cannot submit final versions until they know their papers have been accepted. You may want to update the the “Who can see paper decisions” setting.");
    if (($sv->has_savedv("seedec") || !$group || $group === "decisions")
        && $sv->newv("seedec") == Conf::SEEDEC_ALL
        && $sv->newv("au_seerev") == Conf::AUSEEREV_NO)
        $sv->set_warning(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");
    if (($sv->has_savedv("au_seerev") || !$group || $group === "decisions")
        && $sv->newv("au_seerev") == Conf::AUSEEREV_TAGS
        && !$sv->newv("tag_au_seerev")
        && !$sv->has_error("tag_au_seerev"))
        $sv->set_warning("tag_au_seerev", "You haven’t set any review visibility tags.");
}

function do_setting_update($sv) {
    global $Conf, $Group, $Me, $Now, $Opt, $OptOverride;
    // parse settings
    foreach (Si::$all as $tag => $si)
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
        $result = $Conf->q("select ifnull(min(paperId),0) from Paper where $x");
        if (($row = edb_row($result)) && $row[0] != $Conf->setting("papersub"))
            $sv->save("papersub", $row[0]);
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
    if (!$sv->has_errors()
        && (count($sv->savedv) || count($sv->save_callbacks))) {
        $tables = "Settings write";
        foreach ($sv->need_lock as $t => $need)
            if ($need)
                $tables .= ", $t write";
        $Conf->qe("lock tables $tables");

        // apply settings
        foreach ($sv->save_callbacks as $si)
            $sv->parsers[$si->parser]->save($sv, $si);

        $dv = $aq = $av = array();
        foreach ($sv->savedv as $n => $v) {
            if (substr($n, 0, 4) === "opt." && $v !== null) {
                $okey = substr($n, 4);
                $oldv = (array_key_exists($okey, $OptOverride) ? $OptOverride[$okey] : get($Opt, $okey));
                $Opt[$okey] = ($v[1] === null ? $v[0] : $v[1]);
                if ($oldv === $Opt[$okey]) {
                    $dv[] = $n;
                    continue; // do not save value in database
                } else if (!array_key_exists($okey, $OptOverride))
                    $OptOverride[$okey] = $oldv;
            }
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
        $Me->log_activity("Updated settings group '$Group'");
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
        $Conf->confirmMsg("Changes saved.");
        redirectSelf();
    } else {
        setting_warnings($sv, $Group);
        $sv->report();
    }
}
if (isset($_REQUEST["update"]) && check_post())
    do_setting_update($Sv);
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();


function doRadio($name, $varr) {
    global $Sv;
    $x = $Sv->sv($name);
    if ($x === null || !isset($varr[$x]))
        $x = 0;
    echo "<table style=\"margin-top:0.25em\">\n";
    foreach ($varr as $k => $text) {
        echo '<tr><td class="nw">', Ht::radio($name, $k, $k == $x, $Sv->sjs($name, array("id" => "{$name}_{$k}"))),
            "&nbsp;</td><td>";
        if (is_array($text))
            echo $Sv->label($name, $text[0], true), "<br /><small>", $text[1], "</small>";
        else
            echo $Sv->label($name, $text, true);
        echo "</td></tr>\n";
    }
    echo "</table>\n";
}

function render_entry($name, $v, $size = 30, $temptext = "") {
    global $Sv;
    $js = ["size" => $size, "placeholder" => $temptext];
    if (($si = Si::get($name))) {
        if ($si->size)
            $js["size"] = $si->size;
        if ($si->placeholder)
            $js["placeholder"] = $si->placeholder;
    }
    return Ht::entry($name, $v, $Sv->sjs($name, $js));
}

function doTextRow($name, $text, $v, $size = 30,
                   $capclass = "lcaption", $tempText = "") {
    global $Conf, $Sv;
    $nametext = (is_array($text) ? $text[0] : $text);
    echo '<tr><td class="', $capclass, ' nw">', $Sv->label($name, $nametext),
        '</td><td class="lentry">', render_entry($name, $v, $size, $tempText);
    if (is_array($text) && get($text, 2))
        echo $text[2];
    if (is_array($text) && get($text, 1))
        echo "<br /><span class='hint'>", $text[1], "</span>";
    echo "</td></tr>\n";
}

function doEntry($name, $v, $size = 30) {
    echo render_entry($name, $v, $size);
}

function date_value($name, $temptext, $othername = null) {
    global $Conf, $Sv;
    $x = $Sv->sv($name);
    if ($x !== null && $Sv->use_req())
        return $x;
    if ($othername && $Sv->sv($othername) == $x)
        return $temptext;
    if ($temptext !== "N/A" && $temptext !== "none" && $x === 0)
        return "none";
    else if ($x <= 0)
        return $temptext;
    else if ($x == 1)
        return "now";
    else
        return $Conf->parseableTime($x, true);
}

function doDateRow($name, $text, $othername = null, $capclass = "lcaption") {
    global $DateExplanation;
    if ($DateExplanation) {
        if (is_array($text))
            $text[1] = $DateExplanation . "<br />" . $text[1];
        else
            $text = array($text, $DateExplanation);
        $DateExplanation = "";
    }
    doTextRow($name, $text, date_value($name, "N/A", $othername), 30, $capclass, "N/A");
}

function doGraceRow($name, $text, $capclass = "lcaption") {
    global $GraceExplanation, $Sv;
    if (!isset($GraceExplanation)) {
        $text = array($text, "Example: “15 min”");
        $GraceExplanation = true;
    }
    doTextRow($name, $text, unparseGrace($Sv->sv($name)), 15, $capclass, "none");
}

function doActionArea($top) {
    echo "<div class='aa'>",
        Ht::submit("update", "Save changes", array("class" => "bb")),
        " &nbsp;", Ht::submit("cancel", "Cancel"), "</div>";
}



// Accounts
function doAccGroup($sv) {
    global $Conf, $Me;

    if ($sv->sv("acct_addr"))
        $sv->echo_checkbox("acct_addr", "Collect users’ addresses and phone numbers");

    echo "<h3 class=\"settings g\">Program committee &amp; system administrators</h3>";

    echo "<p><a href='", hoturl("profile", "u=new&amp;role=pc"), "' class='button'>Create PC account</a> &nbsp;|&nbsp; ",
        "Select a user’s name to edit a profile.</p>\n";
    $pl = new ContactList($Me, false);
    echo $pl->table_html("pcadminx", hoturl("users", "t=pcadmin"));
}

// Messages
function do_message($name, $description, $type, $rows = 10, $hint = "") {
    global $Conf, $Sv;
    $defaultname = $name;
    if (is_array($name))
        list($name, $defaultname) = $name;
    $default = $Conf->message_default_html($defaultname);
    $current = $Sv->sv($name, $default);
    echo '<div class="fold', ($current == $default ? "c" : "o"),
        '" data-fold="true">',
        '<div class="', ($type ? "f-cn" : "f-cl"),
        ' childfold" onclick="return foldup(this,event)">',
        '<a class="q" href="#" onclick="return foldup(this,event)">',
        expander(null, 0), $Sv->label($name, $description),
        '</a> <span class="f-cx fx">(HTML allowed)</span></div>',
        $hint,
        Ht::textarea($name, $current, $Sv->sjs($name, array("class" => "fx", "rows" => $rows, "cols" => 80))),
        '</div><div class="g"></div>', "\n";
}

function doInfoGroup($sv) {
    global $Conf, $Opt;

    echo '<div class="f-c">', $sv->label("opt.shortName", "Conference abbreviation"), "</div>\n";
    doEntry("opt.shortName", $sv->sv("opt.shortName"), 20);
    echo '<div class="f-h">Examples: “HotOS XIV”, “NSDI \'14”</div>';
    echo "<div class=\"g\"></div>\n";

    $long = $sv->sv("opt.longName");
    if ($long == $sv->sv("opt.shortName"))
        $long = "";
    echo "<div class='f-c'>", $sv->label("opt.longName", "Conference name"), "</div>\n";
    doEntry("opt.longName", $long, 70);
    echo '<div class="f-h">Example: “14th Workshop on Hot Topics in Operating Systems”</div>';
    echo "<div class=\"g\"></div>\n";

    echo "<div class='f-c'>", $sv->label("opt.conferenceSite", "Conference URL"), "</div>\n";
    doEntry("opt.conferenceSite", $sv->sv("opt.conferenceSite"), 70);
    echo '<div class="f-h">Example: “http://yourconference.org/”</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.contactName", "Name of site contact"), "</div>\n";
    doEntry("opt.contactName", $sv->sv("opt.contactName"), 50);
    echo '<div class="g"></div>', "\n";

    echo "<div class='f-c'>", $sv->label("opt.contactEmail", "Email of site contact"), "</div>\n";
    doEntry("opt.contactEmail", $sv->sv("opt.contactEmail"), 40);
    echo '<div class="f-h">The site contact is the contact point for users if something goes wrong. It defaults to the chair.</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.emailReplyTo", "Reply-To field for email"), "</div>\n";
    doEntry("opt.emailReplyTo", $sv->sv("opt.emailReplyTo"), 80);
    echo '<div class="g"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.emailCc", "Default Cc for reviewer email"), "</div>\n";
    doEntry("opt.emailCc", $sv->sv("opt.emailCc"), 80);
    echo '<div class="f-h">This applies to email sent to reviewers and email sent using the <a href="', hoturl("mail"), '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.</div>';
}

function doMsgGroup($sv) {
    do_message("msg.home", "Home page message", 0);
    do_message("msg.clickthrough_submit", "Clickthrough submission terms", 0, 10,
               "<div class=\"hint fx\">Users must “accept” these terms to edit or submit a paper. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
    do_message("msg.submit", "Submission message", 0, 5,
               "<div class=\"hint fx\">This message will appear on paper editing pages.</div>");
    do_message("msg.clickthrough_review", "Clickthrough reviewing terms", 0, 10,
               "<div class=\"hint fx\">Users must “accept” these terms to edit a review. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
    do_message("msg.conflictdef", "Definition of conflict of interest", 0, 5);
    do_message("msg.revprefdescription", "Review preference instructions", 0, 20);
}

// Submissions
function doSubGroup($sv) {
    global $Conf, $Opt;

    $sv->echo_checkbox('sub_open', '<b>Open site for submissions</b>');

    echo "<div class='g'></div>\n";
    echo "<strong>Blind submission:</strong> Are author names hidden from reviewers?<br />\n";
    doRadio("sub_blind", array(Conf::BLIND_ALWAYS => "Yes—submissions are anonymous",
                               Conf::BLIND_NEVER => "No—author names are visible to reviewers",
                               Conf::BLIND_UNTILREVIEW => "Blind until review—reviewers can see author names after submitting a review",
                               Conf::BLIND_OPTIONAL => "Depends—authors decide whether to expose their names"));

    echo "<div class='g'></div>\n<table>\n";
    doDateRow("sub_reg", "Registration deadline", "sub_sub");
    doDateRow("sub_sub", "Submission deadline");
    doGraceRow("sub_grace", 'Grace period');
    echo "</table>\n";

    doRadio("sub_freeze", array(0 => "Allow updates until the submission deadline (usually the best choice)", 1 => "Authors must freeze the final version of each submission"));


    echo "<div class='g'></div><table>\n";
    $sv->echo_checkbox_row('pc_seeall', "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences before most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>");
    echo "</table>";
}

// Submission options
function option_search_term($oname) {
    $owords = preg_split(',[^a-z_0-9]+,', strtolower(trim($oname)));
    for ($i = 0; $i < count($owords); ++$i) {
        $attempt = join("-", array_slice($owords, 0, $i + 1));
        if (count(PaperOption::search($attempt)) == 1)
            return $attempt;
    }
    return simplify_whitespace($oname);
}

function doOptGroupOption($sv, $o) {
    global $Conf;

    if ($o)
        $id = $o->id;
    else {
        $o = PaperOption::make(array("id" => $o,
                "name" => "(Enter new option)",
                "description" => "",
                "type" => "checkbox",
                "position" => count(PaperOption::option_list()) + 1,
                "display" => "default"));
        $id = "n";
    }

    if ($sv->use_req() && isset($sv->req["optn$id"])) {
        $o = PaperOption::make(array("id" => $id,
                "name" => $sv->req["optn$id"],
                "description" => get($sv->req, "optd$id"),
                "type" => get($sv->req, "optvt$id", "checkbox"),
                "visibility" => get($sv->req, "optp$id", ""),
                "position" => get($sv->req, "optfp$id", 1),
                "display" => get($sv->req, "optdt$id")));
        if ($o->has_selector())
            $o->selector = explode("\n", rtrim(defval($sv->req, "optv$id", "")));
    }

    echo "<tr><td><div class='f-contain'>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        $sv->label("optn$id", ($id === "n" ? "New option name" : "Option name")),
        "</div>",
        "<div class='f-e'>",
        Ht::entry("optn$id", $o->name, $sv->sjs("optn$id", array("placeholder" => "(Enter new option)", "size" => 50))),
        "</div>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        $sv->label("optd$id", "Description"),
        "</div>",
        "<div class='f-e'>",
        Ht::textarea("optd$id", $o->description, array("rows" => 2, "cols" => 50, "id" => "optd$id")),
        "</div>",
        "</div></td>";

    if ($id !== "n" && ($examples = $o->example_searches())) {
        echo "<td style='padding-left: 1em'><div class='f-i'>",
            "<div class='f-c'>Example " . pluralx($examples, "search") . "</div>";
        foreach ($examples as &$ex)
            $ex = "<a href=\"" . hoturl("search", array("q" => $ex[0])) . "\">" . htmlspecialchars($ex[0]) . "</a>";
        echo '<div class="f-e">', join("<br/>", $examples), "</div></div></td>";
    }

    echo "</tr>\n  <tr><td colspan='2'><table id='foldoptvis$id' class='fold2c fold3o'><tr>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        $sv->label("optvt$id", "Type"), "</div><div class='f-e'>";

    $optvt = $o->type;
    if ($optvt == "text" && $o->display_space > 3)
        $optvt .= ":ds_" . $o->display_space;
    if ($o->final)
        $optvt .= ":final";

    $show_final = $Conf->collectFinalPapers();
    foreach (PaperOption::option_list() as $ox)
        $show_final = $show_final || $ox->final;

    $otypes = array();
    if ($show_final)
        $otypes["xxx1"] = array("optgroup", "Options for submissions");
    $otypes["checkbox"] = "Checkbox";
    $otypes["selector"] = "Selector";
    $otypes["radio"] = "Radio buttons";
    $otypes["numeric"] = "Numeric";
    $otypes["text"] = "Text";
    if ($o->type == "text" && $o->display_space > 3 && $o->display_space != 5)
        $otypes[$optvt] = "Multiline text";
    else
        $otypes["text:ds_5"] = "Multiline text";
    $otypes["pdf"] = "PDF";
    $otypes["slides"] = "Slides";
    $otypes["video"] = "Video";
    $otypes["attachments"] = "Attachments";
    if ($show_final) {
        $otypes["xxx2"] = array("optgroup", "Options for accepted papers");
        $otypes["pdf:final"] = "Alternate final version";
        $otypes["slides:final"] = "Final slides";
        $otypes["video:final"] = "Final video";
    }
    echo Ht::select("optvt$id", $otypes, $optvt, array("onchange" => "do_option_type(this)", "id" => "optvt$id")),
        "</div></div></td>";
    $Conf->footerScript("do_option_type(\$\$('optvt$id'),true)");

    echo "<td class='fn2 pad'><div class='f-i'><div class='f-c'>",
        $sv->label("optp$id", "Visibility"), "</div><div class='f-e'>",
        Ht::select("optp$id", array("admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"), $o->visibility, array("id" => "optp$id")),
        "</div></div></td>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        $sv->label("optfp$id", "Form order"), "</div><div class='f-e'>";
    $x = array();
    // can't use "foreach (PaperOption::option_list())" because caller
    // uses cursor
    for ($n = 0; $n < count(PaperOption::option_list()); ++$n)
        $x[$n + 1] = ordinal($n + 1);
    if ($id === "n")
        $x[$n + 1] = ordinal($n + 1);
    else
        $x["delete"] = "Delete option";
    echo Ht::select("optfp$id", $x, $o->position, array("id" => "optfp$id")),
        "</div></div></td>";

    echo "<td class='pad fn3'><div class='f-i'><div class='f-c'>",
        $sv->label("optdt$id", "Display"), "</div><div class='f-e'>";
    echo Ht::select("optdt$id", ["default" => "Default",
                                 "prominent" => "Prominent",
                                 "topics" => "With topics",
                                 "submission" => "Near submission"],
                    $o->display_name(), array("id" => "optdt$id")),
        "</div></div></td>";

    if (isset($otypes["pdf:final"]))
        echo "<td class='pad fx2'><div class='f-i'><div class='f-c'>&nbsp;</div><div class='f-e hint' style='margin-top:0.7ex'>(Set by accepted authors during final version submission period)</div></div></td>";

    echo "</tr></table>";

    $rows = 3;
    if (PaperOption::type_has_selector($optvt) && count($o->selector)) {
        $value = join("\n", $o->selector) . "\n";
        $rows = max(count($o->selector), 3);
    } else
        $value = "";
    echo "<div id='foldoptv$id' class='", (PaperOption::type_has_selector($optvt) ? "foldo" : "foldc"),
        "'><div class='fx'>",
        "<div class='hint' style='margin-top:1ex'>Enter choices one per line.  The first choice will be the default.</div>",
        Ht::textarea("optv$id", $value, $sv->sjs("optv$id", array("rows" => $rows, "cols" => 50))),
        "</div></div>";

    echo "</div></td></tr>\n";
}

function opt_yes_no_optional($name) {
    global $Opt;
    if (($x = get($Opt, $name)) === 1 || $x === true)
        return 1;
    if ($x === 2)
        return 2;
    return 0;
}

function doOptGroup($sv) {
    global $Conf, $Opt;

    echo "<h3 class=\"settings\">Basics</h3>\n";

    echo Ht::select("sub_noabstract", [0 => "Abstract required", 2 => "Abstract optional", 1 => "No abstract"], opt_yes_no_optional("noAbstract"));

    echo " <span class=\"barsep\">·</span> ", Ht::select("sub_nopapers", array(0 => "PDF upload required", 2 => "PDF upload optional", 1 => "No PDF"), opt_yes_no_optional("noPapers"));

    if (is_executable("src/banal")) {
        echo "<div class='g'></div>",
            Ht::hidden("has_sub_banal", 1),
            "<table id='foldbanal' class='", ($sv->sv("sub_banal") ? "foldo" : "foldc"), "'>";
        $sv->echo_checkbox_row("sub_banal", "PDF format checker<span class='fx'>:</span>", "void fold('banal',!this.checked)");
        echo "<tr class='fx'><td></td><td class='top'><table>";
        $bsetting = explode(";", preg_replace("/>.*/", "", $Conf->setting_data("sub_banal", "")));
        for ($i = 0; $i < 6; $i++)
            if (defval($bsetting, $i, "") == "")
                $bsetting[$i] = "N/A";
        doTextRow("sub_banal_papersize", array("Paper size", "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”,<br />“letter OR A4”"), $sv->inputv("sub_banal_papersize", $bsetting[0]), 18, "lxcaption", "N/A");
        doTextRow("sub_banal_pagelimit", "Page limit", $sv->inputv("sub_banal_pagelimit", $bsetting[1]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_textblock", array("Text block", "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”"), $sv->inputv("sub_banal_textblock", $bsetting[3]), 18, "lxcaption", "N/A");
        echo "</table></td><td><span class='sep'></span></td><td class='top'><table>";
        doTextRow("sub_banal_bodyfontsize", array("Minimum body font size", null, "&nbsp;pt"), $sv->inputv("sub_banal_bodyfontsize", $bsetting[4]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_bodyleading", array("Minimum leading", null, "&nbsp;pt"), $sv->inputv("sub_banal_bodyleading", $bsetting[5]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_columns", array("Columns", null), $sv->inputv("sub_banal_columns", $bsetting[2]), 4, "lxcaption", "N/A");
        echo "</table></td></tr></table>";
    }

    echo "<h3 class=\"settings\">Conflicts &amp; collaborators</h3>\n",
        "<table id=\"foldpcconf\" class=\"fold",
        ($sv->sv("sub_pcconf") ? "o" : "c"), "\">\n";
    $sv->echo_checkbox_row("sub_pcconf", "Collect authors’ PC conflicts",
                           "void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    $conf = array();
    foreach (Conflict::$type_descriptions as $n => $d)
        if ($n)
            $conf[] = "“{$d}”";
    $sv->echo_checkbox("sub_pcconfsel", "Require conflict descriptions (" . commajoin($conf, "or") . ")");
    echo "</td></tr>\n";
    $sv->echo_checkbox_row("sub_collab", "Collect authors’ other collaborators as text");
    echo "</table>\n";


    echo "<h3 class=\"settings\">Submission options</h3>\n";
    echo "Options are selected by authors at submission time.  Examples have included “PC-authored paper,” “Consider this paper for a Best Student Paper award,” and “Allow the shadow PC to see this paper.”  The “option name” should be brief (“PC paper,” “Best Student Paper,” “Shadow PC”).  The optional description can explain further and may use XHTML.  ";
    echo "Add options one at a time.\n";
    echo "<div class='g'></div>\n",
        Ht::hidden("has_options", 1),
        "<table>";
    $sep = "";
    $all_options = array_merge(PaperOption::option_list()); // get our own iterator
    foreach ($all_options as $o) {
        echo $sep;
        doOptGroupOption($sv, $o);
        $sep = "<tr><td colspan='2'><hr class='hr' /></td></tr>\n";
    }

    echo $sep;

    doOptGroupOption($sv, null);

    echo "</table>\n";


    // Topics
    // load topic interests
    $qinterest = $Conf->query_topic_interest();
    $result = $Conf->q("select topicId, if($qinterest>0,1,0), count(*) from TopicInterest where $qinterest!=0 group by topicId, $qinterest>0");
    $interests = array();
    $ninterests = 0;
    while (($row = edb_row($result))) {
        if (!isset($interests[$row[0]]))
            $interests[$row[0]] = array();
        $interests[$row[0]][$row[1]] = $row[2];
        $ninterests += ($row[2] ? 1 : 0);
    }

    echo "<h3 class=\"settings g\">Topics</h3>\n";
    echo "Enter topics one per line.  Authors select the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its name.\n";
    echo "<div class='g'></div>",
        Ht::hidden("has_topics", 1),
        "<table id='newtoptable' class='", ($ninterests ? "foldo" : "foldc"), "'>";
    echo "<tr><th colspan='2'></th><th class='fx'><small>Low</small></th><th class='fx'><small>High</small></th></tr>";
    $td1 = '<td class="lcaption">Current</td>';
    foreach ($Conf->topic_map() as $tid => $tname) {
        if ($sv->use_req() && isset($sv->req["top$tid"]))
            $tname = $sv->req["top$tid"];
        echo '<tr>', $td1, '<td class="lentry">',
            Ht::entry("top$tid", $tname, array("size" => 40, "style" => "width:20em")),
            '</td>';

        $tinterests = defval($interests, $tid, array());
        echo '<td class="fx rpentry">', (get($tinterests, 0) ? '<span class="topic-2">' . $tinterests[0] . "</span>" : ""), "</td>",
            '<td class="fx rpentry">', (get($tinterests, 1) ? '<span class="topic2">' . $tinterests[1] . "</span>" : ""), "</td>";

        if ($td1 !== "<td></td>") {
            // example search
            echo "<td class='llentry' style='vertical-align:top' rowspan='40'><div class='f-i'>",
                "<div class='f-c'>Example search</div>";
            $oabbrev = strtolower($tname);
            if (strstr($oabbrev, " ") !== false)
                $oabbrev = "\"$oabbrev\"";
            echo "“<a href=\"", hoturl("search", "q=topic:" . urlencode($oabbrev)), "\">",
                "topic:", htmlspecialchars($oabbrev), "</a>”",
                "<div class='hint'>Topic abbreviations are also allowed.</div>";
            if ($ninterests)
                echo "<a class='hint fn' href=\"#\" onclick=\"return fold('newtoptable')\">Show PC interest counts</a>",
                    "<a class='hint fx' href=\"#\" onclick=\"return fold('newtoptable')\">Hide PC interest counts</a>";
            echo "</div></td>";
        }
        echo "</tr>\n";
        $td1 = "<td></td>";
    }
    echo '<tr><td class="lcaption top" rowspan="40">New<br><span class="hint">Enter one topic per line.</span></td><td class="lentry top">',
        Ht::textarea("topnew", $sv->use_req() ? get($sv->req, "topnew") : "", array("cols" => 40, "rows" => 2, "style" => "width:20em")),
        '</td></tr></table>';
}

// Reviews
function echo_round($sv, $rnum, $nameval, $review_count, $deletable) {
    global $Conf;
    $rname = "roundname_$rnum";
    if ($sv->use_req() && $rnum !== '$')
        $nameval = (string) get($sv->req, $rname);

    $default_rname = "unnamed";
    if ($nameval === "(new round)" || $rnum === '$')
        $default_rname = "(new round)";
    echo '<div class="mg" data-round-number="', $rnum, '"><div>',
        $sv->label($rname, "Round"), ' &nbsp;',
        render_entry($rname, $nameval, 12, $default_rname);
    echo '<div class="inb" style="min-width:7em;margin-left:2em">';
    if ($rnum !== '$' && $review_count)
        echo '<a href="', hoturl("search", "q=" . urlencode("round:" . ($rnum ? $Conf->round_name($rnum, false) : "none"))), '">(', plural($review_count, "review"), ')</a>';
    echo '</div>';
    if ($deletable)
        echo '<div class="inb" style="padding-left:2em">',
            Ht::hidden("deleteround_$rnum", ""),
            Ht::js_button("Delete round", "review_round_settings.kill(this)"),
            '</div>';
    if ($rnum === '$')
        echo '<div class="hint">Names like “R1” and “R2” work well.</div>';
    echo '</div>';

    // deadlines
    $entrysuf = $rnum ? "_$rnum" : "";
    if ($rnum === '$' && count($Conf->round_list()))
        $dlsuf = "_" . (count($Conf->round_list()) - 1);
    else if ($rnum !== '$' && $rnum)
        $dlsuf = "_" . $rnum;
    else
        $dlsuf = "";
    echo '<table style="margin-left:3em">';
    echo '<tr><td>', $sv->label("pcrev_soft$entrysuf", "PC deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("pcrev_soft$entrysuf", date_value("pcrev_soft$dlsuf", "none"), 28, "none"),
        '</td><td class="lentry">', $sv->label("pcrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("pcrev_hard$entrysuf", date_value("pcrev_hard$dlsuf", "none"), 28, "none"),
        '</td></tr>';
    echo '<tr><td>', $sv->label("extrev_soft$entrysuf", "External deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("extrev_soft$entrysuf", date_value("extrev_soft$dlsuf", "same as PC", "pcrev_soft$dlsuf"), 28, "same as PC"),
        '</td><td class="lentry">', $sv->label("extrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("extrev_hard$entrysuf", date_value("extrev_hard$dlsuf", "same as PC", "pcrev_hard$dlsuf"), 28, "same as PC"),
        '</td></tr>';
    echo '</table></div>', "\n";
}

function doRevGroup($sv) {
    global $Conf, $DateExplanation;

    $sv->echo_checkbox("rev_open", "<b>Open site for reviewing</b>");
    $sv->echo_checkbox("cmt_always", "Allow comments even if reviewing is closed");

    echo "<div class='g'></div>\n";
    $sv->echo_checkbox('pcrev_any', "PC members can review <strong>any</strong> submitted paper");

    echo "<div class='g'></div>\n";
    echo "<strong>Review anonymity:</strong> Are reviewer names hidden from authors?<br />\n";
    doRadio("rev_blind", array(Conf::BLIND_ALWAYS => "Yes—reviews are anonymous",
                               Conf::BLIND_NEVER => "No—reviewer names are visible to authors",
                               Conf::BLIND_OPTIONAL => "Depends—reviewers decide whether to expose their names"));

    echo "<div class='g'></div>\n";
    $sv->echo_checkbox('rev_notifychair', 'Notify PC chairs of newly submitted reviews by email');


    // Deadlines
    echo "<h3 id=\"rounds\" class=\"settings g\">Deadlines &amp; rounds</h3>\n";
    $date_text = $DateExplanation;
    $DateExplanation = "";
    echo '<p class="hint">Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.<br />', $date_text, '</p>';

    $rounds = $Conf->round_list();
    if ($sv->use_req()) {
        for ($i = 1; isset($sv->req["roundname_$i"]); ++$i)
            $rounds[$i] = get($sv->req, "deleteround_$i") ? ";" : trim(get_s($sv->req, "roundname_$i"));
    }

    // prepare round selector
    $round_value = trim($sv->sv("rev_roundtag"));
    $current_round_value = $Conf->setting_data("rev_roundtag", "");
    if (preg_match('/\A(?:|\(none\)|\(no name\)|default|unnamed|#0)\z/i', $round_value))
        $round_value = "#0";
    else if (($round_number = $Conf->round_number($round_value, false))
             || ($round_number = $Conf->round_number($current_round_value, false)))
        $round_value = "#" . $round_number;
    else
        $round_value = $selector[$current_round_value] = $current_round_value;

    $round_map = edb_map(Dbl::ql("select reviewRound, count(*) from PaperReview group by reviewRound"));

    $print_round0 = true;
    if ($round_value !== "#0" && $round_value !== ""
        && $current_round_value !== ""
        && (!$sv->use_req() || isset($sv->req["roundname_0"]))
        && !$Conf->round0_defined())
        $print_round0 = false;

    $selector = array();
    if ($print_round0)
        $selector["#0"] = "unnamed";
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] !== ";")
            $selector["#$i"] = (object) array("label" => $rounds[$i], "id" => "rev_roundtag_$i");

    echo '<div id="round_container"', (count($selector) == 1 ? ' style="display:none"' : ''), '>',
        '<table id="rev_roundtag_table"><tr><td class="lxcaption">',
        $sv->label("rev_roundtag", "Current round"),
        '</td><td>',
        Ht::select("rev_roundtag", $selector, $round_value, $sv->sjs("rev_roundtag")),
        '</td></tr></table>',
        '<div class="hint">This round is used for new assignments.</div><div class="g"></div></div>';

    echo '<div id="roundtable">';
    $num_printed = 0;
    for ($i = 0; $i < count($rounds); ++$i)
        if ($i ? $rounds[$i] !== ";" : $print_round0) {
            echo_round($sv, $i, $i ? $rounds[$i] : "", +get($round_map, $i), count($selector) !== 1);
            ++$num_printed;
        }
    echo '</div><div id="newround" style="display:none">';
    echo_round($sv, '$', "", "", true);
    echo '</div><div class="g"></div>';
    echo Ht::js_button("Add round", "review_round_settings.add();hiliter(this)"),
        ' &nbsp; <span class="hint"><a href="', hoturl("help", "t=revround"), '">What is this?</a></span>',
        Ht::hidden("oldroundcount", count($Conf->round_list())),
        Ht::hidden("has_rev_roundtag", 1);
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] === ";")
            echo Ht::hidden("roundname_$i", "", array("id" => "roundname_$i")),
                Ht::hidden("deleteround_$i", 1);
    Ht::stash_script("review_round_settings.init()");


    // External reviews
    echo "<h3 class=\"settings g\">External reviews</h3>\n";

    echo "<div class='g'></div>";
    $sv->echo_checkbox("extrev_chairreq", "PC chair must approve proposed external reviewers");
    $sv->echo_checkbox("pcrev_editdelegate", "PC members can edit external reviews they requested");

    echo "<div class='g'></div>\n";
    $t = expandMailTemplate("requestreview", false);
    echo "<table id='foldmailbody_requestreview' class='",
        ($t == expandMailTemplate("requestreview", true) ? "foldc" : "foldo"),
        "'><tr><td>", foldbutton("mailbody_requestreview"), "</td>",
        "<td><a href='#' onclick='return fold(\"mailbody_requestreview\")' class='q'><strong>Mail template for external review requests</strong></a>",
        " <span class='fx'>(<a href='", hoturl("mail"), "'>keywords</a> allowed; set to empty for default)<br /></span>
<textarea class='tt fx' name='mailbody_requestreview' cols='80' rows='20'>", htmlspecialchars($t["body"]), "</textarea>",
        "</td></tr></table>\n";


    // Review visibility
    echo "<h3 class=\"settings g\">Visibility</h3>\n";

    echo "Can PC members <strong>see all reviews</strong> except for conflicts?<br />\n";
    doRadio("pc_seeallrev", array(Conf::PCSEEREV_YES => "Yes",
                                  Conf::PCSEEREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same paper",
                                  Conf::PCSEEREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                                  Conf::PCSEEREV_IFCOMPLETE => "Only after completing a review for the same paper"));

    echo "<div class='g'></div>\n";
    echo "Can PC members see <strong>reviewer names</strong> except for conflicts?<br />\n";
    doRadio("pc_seeblindrev", array(0 => "Yes",
                                    1 => "Only after completing a review for the same paper<br /><span class='hint'>This setting also hides reviewer-only comments from PC members who have not completed a review for the same paper.</span>"));

    echo "<div class='g'></div>";
    echo "Can external reviewers see the other reviews for their assigned papers, once they’ve submitted their own?<br />\n";
    doRadio("extrev_view", array(2 => "Yes", 1 => "Yes, but they can’t see who wrote blind reviews", 0 => "No"));


    // Review ratings
    echo "<h3 class=\"settings g\">Review ratings</h3>\n";

    echo "Should HotCRP collect ratings of reviews? &nbsp; <a class='hint' href='", hoturl("help", "t=revrate"), "'>(Learn more)</a><br />\n";
    doRadio("rev_ratings", array(REV_RATINGS_PC => "Yes, PC members can rate reviews", REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews", REV_RATINGS_NONE => "No"));
}

// Tags and tracks
function do_track_permission($sv, $type, $question, $tnum, $thistrack) {
    global $Conf;
    $tclass = $ttag = "";
    if ($sv->use_req()) {
        $tclass = defval($sv->req, "${type}_track$tnum", "");
        $ttag = defval($sv->req, "${type}tag_track$tnum", "");
    } else if ($thistrack && get($thistrack, $type)) {
        if ($thistrack->$type == "+none")
            $tclass = "none";
        else {
            $tclass = substr($thistrack->$type, 0, 1);
            $ttag = substr($thistrack->$type, 1);
        }
    }

    $hint = "";
    if (is_array($question))
        list($question, $hint) = [$question[0], '<p class="hint" style="margin:0;max-width:480px">' . $question[1] . '</p>'];

    echo "<tr data-fold=\"true\" class=\"fold", ($tclass == "" || $tclass == "none" ? "c" : "o"), "\">";
    if ($type === "viewtracker")
        echo "<td class=\"lxcaption\" colspan=\"2\" style=\"padding-top:0.5em\">";
    else
        echo "<td style=\"width:2em\"></td><td class=\"lxcaption\">";
    echo $sv->label(["{$type}_track$tnum", "{$type}tag_track$tnum"],
                    $question, "{$type}_track$tnum"),
        "</td><td>",
        Ht::select("{$type}_track$tnum",
                   array("" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"),
                   $tclass,
                   $sv->sjs("{$type}_track$tnum", array("onchange" => "void foldup(this,event,{f:this.selectedIndex==0||this.selectedIndex==3})"))),
        " &nbsp;</td><td style=\"min-width:120px\">",
        Ht::entry("${type}tag_track$tnum", $ttag,
                  $sv->sjs("{$type}tag_track$tnum", array("class" => "fx", "placeholder" => "(tag)"))),
        "</td></tr>";
    if ($hint)
        echo "<tr><td></td><td colspan=\"3\" style=\"padding-bottom:2px\">", $hint, "</td></tr>";
}

function do_track($sv, $trackname, $tnum) {
    global $Conf;
    echo "<div id=\"trackgroup$tnum\"",
        ($tnum ? "" : " style=\"display:none\""),
        "><table style=\"margin-bottom:0.5em\">";
    echo "<tr><td colspan=\"3\" style=\"padding-bottom:3px\">";
    if ($trackname === "_")
        echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
    else
        echo $sv->label("name_track$tnum", "For papers with tag &nbsp;"),
            Ht::entry("name_track$tnum", $trackname, $sv->sjs("name_track$tnum", array("placeholder" => "(tag)"))), ":";
    echo "</td></tr>\n";

    $t = $Conf->setting_json("tracks");
    $t = $t && $trackname !== "" ? get($t, $trackname) : null;
    do_track_permission($sv, "view", "Who can see these papers?", $tnum, $t);
    do_track_permission($sv, "viewpdf", ["Who can see PDFs?", "Assigned reviewers can always view PDFs."], $tnum, $t);
    do_track_permission($sv, "viewrev", "Who can see reviews?", $tnum, $t);
    $hint = "";
    if ($Conf->setting("pc_seeblindrev"))
        $hint = "Regardless of this setting, PC members can’t see reviewer names until they’ve completed a review for the same paper (<a href=\"" . hoturl("settings", "group=reviews") . "\">Settings &gt; Reviews &gt; Visibility</a>).";
    do_track_permission($sv, "viewrevid", ["Who can see reviewer names?", $hint], $tnum, $t);
    do_track_permission($sv, "assrev", "Who can be assigned a review?", $tnum, $t);
    do_track_permission($sv, "unassrev", "Who can self-assign a review?", $tnum, $t);
    if ($trackname === "_")
        do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", $tnum, $t);
    echo "</table></div>\n\n";
}

function doTagsGroup($sv) {
    global $Conf, $DateExplanation;

    // Tags
    $tagger = new Tagger;
    echo "<h3 class=\"settings\">Tags</h3>\n";

    echo "<table><tr><td class='lxcaption'>", $sv->label("tag_chair", "Chair-only tags"), "</td>";
    if ($sv->use_req())
        $v = defval($sv->req, "tag_chair", "");
    else
        $v = join(" ", array_keys(TagInfo::chair_tags()));
    echo "<td>", Ht::hidden("has_tag_chair", 1);
    doEntry("tag_chair", $v, 40);
    echo "<br /><div class='hint'>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</div></td></tr>";

    echo "<tr><td class='lxcaption'>", $sv->label("tag_approval", "Approval voting tags"), "</td>";
    if ($sv->use_req())
        $v = defval($sv->req, "tag_approval", "");
    else {
        $x = "";
        foreach (TagInfo::approval_tags() as $n => $v)
            $x .= "$n ";
        $v = trim($x);
    }
    echo "<td>", Ht::hidden("has_tag_approval", 1);
    doEntry("tag_approval", $v, 40);
    echo "<br /><div class='hint'><a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lxcaption'>", $sv->label("tag_vote", "Allotment voting tags"), "</td>";
    if ($sv->use_req())
        $v = defval($sv->req, "tag_vote", "");
    else {
        $x = "";
        foreach (TagInfo::vote_tags() as $n => $v)
            $x .= "$n#$v ";
        $v = trim($x);
    }
    echo "<td>", Ht::hidden("has_tag_vote", 1);
    doEntry("tag_vote", $v, 40);
    echo "<br /><div class='hint'>“vote#10” declares an allotment of 10 votes per PC member. <span class='barsep'>·</span> <a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lxcaption'>", $sv->label("tag_rank", "Ranking tag"), "</td>";
    if ($sv->use_req())
        $v = defval($sv->req, "tag_rank", "");
    else
        $v = $Conf->setting_data("tag_rank", "");
    echo "<td>", Ht::hidden("has_tag_rank", 1);
    doEntry("tag_rank", $v, 40);
    echo "<br /><div class='hint'>The <a href='", hoturl("offline"), "'>offline reviewing page</a> will expose support for uploading rankings by this tag. <span class='barsep'>·</span> <a href='", hoturl("help", "t=ranking"), "'>What is this?</a></div></td></tr>";
    echo "</table>";

    echo "<div class='g'></div>\n";
    $sv->echo_checkbox('tag_seeall', "PC can see tags for conflicted papers");

    preg_match_all('_(\S+)=(\S+)_', $Conf->setting_data("tag_color", ""), $m,
                   PREG_SET_ORDER);
    $tag_colors = array();
    foreach ($m as $x)
        $tag_colors[TagInfo::canonical_color($x[2])][] = $x[1];
    $tag_colors_rows = array();
    foreach (explode("|", TagInfo::BASIC_COLORS) as $k) {
        if ($sv->use_req())
            $v = defval($sv->req, "tag_color_$k", "");
        else if (isset($tag_colors[$k]))
            $v = join(" ", $tag_colors[$k]);
        else
            $v = "";
        $tag_colors_rows[] = "<tr class='k0 ${k}tag'><td class='lxcaption'></td><td class='lxcaption taghl'>$k</td><td class='lentry' style='font-size: 10.5pt'><input type='text' name='tag_color_$k' value=\"" . htmlspecialchars($v) . "\" size='40' /></td></tr>"; /* MAINSIZE */
    }

    preg_match_all('_(\S+)=(\S+)_', $Conf->setting_data("tag_badge", ""), $m,
                   PREG_SET_ORDER);
    $tag_badges = array();
    foreach ($m as $x)
        $tag_badges[$x[2]][] = $x[1];
    foreach (["black" => "black label", "red" => "red label", "green" => "green label",
              "blue" => "blue label", "white" => "white label"]
             as $k => $desc) {
        if ($sv->use_req())
            $v = defval($sv->req, "tag_badge_$k", "");
        else if (isset($tag_badges[$k]))
            $v = join(" ", $tag_badges[$k]);
        else
            $v = "";
        $tag_colors_rows[] = "<tr class='k0'><td class='lxcaption'></td><td class='lxcaption'><span class='badge {$k}badge' style='margin:0'>$desc</span><td class='lentry' style='font-size:10.5pt'><input type='text' name='tag_badge_$k' value=\"" . htmlspecialchars($v) . "\" size='40' /></td></tr>"; /* MAINSIZE */
    }

    echo Ht::hidden("has_tag_color", 1),
        '<h3 class="settings g">Styles and colors</h3>',
        "<div class='hint'>Papers and PC members tagged with a style name, or with one of the associated tags, will appear in that style in lists.</div>",
        "<div class='smg'></div>",
        "<table id='foldtag_color'><tr><th colspan='2'>Style name</th><th>Tags</th></tr>",
        join("", $tag_colors_rows), "</table>\n";


    echo '<h3 class="settings g">Tracks</h3>', "\n";
    echo "<div class='hint'>Tracks control the PC members allowed to view or review different sets of papers. <span class='barsep'>·</span> <a href=\"" . hoturl("help", "t=tracks") . "\">What is this?</a></div>",
        Ht::hidden("has_tracks", 1),
        "<div class=\"smg\"></div>\n";
    do_track($sv, "", 0);
    $tracknum = 2;
    $trackj = $Conf->setting_json("tracks") ? : (object) array();
    // existing tracks
    foreach ($trackj as $trackname => $x)
        if ($trackname !== "_") {
            do_track($sv, $trackname, $tracknum);
            ++$tracknum;
        }
    // new tracks (if error prevented saving)
    if ($sv->use_req())
        for ($i = 1; isset($sv->req["name_track$i"]); ++$i) {
            $trackname = trim($sv->req["name_track$i"]);
            if (!isset($trackj->$trackname)) {
                do_track($sv, $trackname, $tracknum);
                ++$tracknum;
            }
        }
    // catchall track
    do_track($sv, "_", 1);
    echo Ht::button("Add track", array("onclick" => "settings_add_track()"));
}


// Responses and decisions
function doDecGroup($sv) {
    global $Conf, $Opt;

    echo "Can <b>authors see reviews and author-visible comments</b> for their papers?<br />";
    if ($Conf->setting("resp_active"))
        $no_text = "No, unless responses are open";
    else
        $no_text = "No";
    if (!$Conf->setting("au_seerev", 0)
        && $Conf->timeAuthorViewReviews())
        $no_text .= '<div class="hint">Authors are currently able to see reviews since responses are open.</div>';
    $opts = array(Conf::AUSEEREV_NO => $no_text,
                  Conf::AUSEEREV_YES => "Yes");
    if ($sv->newv("au_seerev") == Conf::AUSEEREV_UNLESSINCOMPLETE
        && !get($Opt, "allow_auseerev_unlessincomplete"))
        $Conf->save_setting("opt.allow_auseerev_unlessincomplete", 1);
    if (get($Opt, "allow_auseerev_unlessincomplete"))
        $opts[Conf::AUSEEREV_UNLESSINCOMPLETE] = "Yes, after completing any assigned reviews for other papers";
    $opts[Conf::AUSEEREV_TAGS] = "Yes, for papers with any of these tags:&nbsp; " . render_entry("tag_au_seerev", $sv->sv("tag_au_seerev"), 24);
    doRadio("au_seerev", $opts);
    echo Ht::hidden("has_tag_au_seerev", 1);

    // Authors' response
    echo '<div class="g"></div><table id="foldauresp" class="fold2o">';
    $sv->echo_checkbox_row('resp_active', "<b>Collect authors’ responses to the reviews<span class='fx2'>:</span></b>", "void fold('auresp',!this.checked,2)");
    echo '<tr class="fx2"><td></td><td><div id="auresparea">',
        Ht::hidden("has_resp_rounds", 1);

    // Response rounds
    if ($sv->use_req()) {
        $rrounds = array(1);
        for ($i = 1; isset($sv->req["resp_roundname_$i"]); ++$i)
            $rrounds[$i] = $sv->req["resp_roundname_$i"];
    } else
        $rrounds = $Conf->resp_round_list();
    $rrounds["n"] = "";
    foreach ($rrounds as $i => $rname) {
        $isuf = $i ? "_$i" : "";
        echo '<div id="response', $isuf;
        if ($i)
            echo '" style="padding-top:1em';
        if ($i === "n")
            echo ';display:none';
        echo '"><table>';
        if (!$i) {
            $rname = $rname == "1" ? "none" : $rname;
            doTextRow("resp_roundname$isuf", "Response name", $rname, 20, "lxcaption", "none");
        } else
            doTextRow("resp_roundname$isuf", "Response name", $rname, 20, "lxcaption");
        if ($sv->sv("resp_open$isuf") === 1 && ($x = $sv->sv("resp_done$isuf")))
            $Conf->settings["resp_open$isuf"] = $x - 7 * 86400;
        doDateRow("resp_open$isuf", "Start time", null, "lxcaption");
        doDateRow("resp_done$isuf", "Hard deadline", null, "lxcaption");
        doGraceRow("resp_grace$isuf", "Grace period", "lxcaption");
        doTextRow("resp_words$isuf", array("Word limit", $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit."),
                  $sv->sv("resp_words$isuf", 500), 5, "lxcaption", "none");
        echo '</table><div style="padding-top:4px">';
        do_message(array("msg.resp_instrux$isuf", "msg.resp_instrux"), "Instructions", 1, 3);
        echo '</div></div>', "\n";
    }

    echo '</div><div style="padding-top:1em">',
        '<button type="button" onclick="settings_add_resp_round()">Add response round</button>',
        '</div></div></td></tr></table>';
    $Conf->footerScript("fold('auresp',!\$\$('cbresp_active').checked,2)");

    echo "<div class='g'></div>\n<hr class='hr' />\n",
        "Who can see paper <b>decisions</b> (accept/reject)?<br />\n";
    doRadio("seedec", array(Conf::SEEDEC_ADMIN => "Only administrators",
                            Conf::SEEDEC_NCREV => "Reviewers and non-conflicted PC members",
                            Conf::SEEDEC_REV => "Reviewers and <em>all</em> PC members",
                            Conf::SEEDEC_ALL => "<b>Authors</b>, reviewers, and all PC members (and reviewers can see accepted papers’ author lists)"));

    echo "<div class='g'></div>\n";
    echo "<table>\n";
    $decs = $Conf->decision_map();
    krsort($decs);

    // count papers per decision
    $decs_pcount = array();
    $result = $Conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
    while (($row = edb_row($result)))
        $decs_pcount[$row[0]] = $row[1];

    // real decisions
    $n_real_decs = 0;
    foreach ($decs as $k => $v)
        $n_real_decs += ($k ? 1 : 0);
    $caption = "<td class='lcaption' rowspan='$n_real_decs'>Current decision types</td>";
    foreach ($decs as $k => $v)
        if ($k) {
            if ($sv->use_req())
                $v = defval($sv->req, "dec$k", $v);
            echo "<tr>", $caption, '<td class="lentry nw">',
                Ht::entry("dec$k", $v, array("size" => 35)),
                " &nbsp; ", ($k > 0 ? "Accept class" : "Reject class"), "</td>";
            if (isset($decs_pcount[$k]) && $decs_pcount[$k])
                echo '<td class="lentry nw">', plural($decs_pcount[$k], "paper"), "</td>";
            echo "</tr>\n";
            $caption = "";
        }

    // new decision
    $v = "";
    $vclass = 1;
    if ($sv->use_req()) {
        $v = defval($sv->req, "decn", $v);
        $vclass = defval($sv->req, "dtypn", $vclass);
    }
    echo '<tr><td class="lcaption">',
        $sv->label("decn", "New decision type"),
        '<br /></td>',
        '<td class="lentry nw">',
        Ht::hidden("has_decisions", 1),
        Ht::entry("decn", $v, array("size" => 35)), ' &nbsp; ',
        Ht::select("dtypn", array(1 => "Accept class", -1 => "Reject class"), $vclass),
        "<br /><small>Examples: “Accepted as short paper”, “Early reject”</small>",
        "</td></tr>";
    if ($sv->has_error("decn"))
        echo '<tr><td></td><td class="lentry nw">',
            Ht::checkbox("decn_confirm", 1, false),
            '&nbsp;<span class="error">', Ht::label("Confirm"), "</span></td></tr>";
    echo "</table>\n";

    // Final versions
    echo "<h3 class=\"settings g\">Final versions</h3>\n";
    echo '<table id="foldfinal" class="fold2o">';
    $sv->echo_checkbox_row('final_open', '<b>Collect final versions of accepted papers<span class="fx">:</span></b>', "void fold('final',!this.checked,2)");
    echo "<tr class='fx2'><td></td><td><table>";
    doDateRow("final_soft", "Deadline", "final_done", "lxcaption");
    doDateRow("final_done", "Hard deadline", null, "lxcaption");
    doGraceRow("final_grace", "Grace period", "lxcaption");
    echo "</table><div class='g'></div>";
    do_message("msg.finalsubmit", "Instructions", 1);
    echo "<div class='g'></div>",
        "<small>To collect <em>multiple</em> final versions, such as one in 9pt and one in 11pt, add “Alternate final version” options via <a href='", hoturl("settings", "group=opt"), "'>Settings &gt; Submission options</a>.</small>",
        "</div></td></tr></table>\n\n";
    $Conf->footerScript("fold('final',!\$\$('cbfinal_open').checked)");
}


if (!$Sv->warnings_reported) {
    setting_warnings($Sv, $Group);
    $Sv->report();
}

$Conf->header("Settings &nbsp;&#x2215;&nbsp; <strong>" . SettingGroup::$all[$Group]->description . "</strong>", "settings", actionBar());
$Conf->echoScript(""); // clear out other script references
echo $Conf->make_script_file("scripts/settings.js"), "\n";

echo Ht::form(hoturl_post("settings", "group=$Group"), array("id" => "settingsform")), "<div>";

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

echo "<div class='aahc'>";
doActionArea(true);
echo "<div>";

SettingGroup::$all[$Group]->render($Sv);

echo "</div>";
doActionArea(false);
echo "</div></div></div></div></form>\n";

$Conf->footerScript("hiliter_children('#settingsform');jQuery('textarea').autogrow()");
$Conf->footer();
