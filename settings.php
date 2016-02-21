<?php
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->privChair)
    $Me->escape();

$Values = array();
$DateExplanation = "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2014-10-31 00:00 UTC-1100” <a href='http://php.net/manual/en/datetime.formats.php'>(more examples)</a>";

// setting information
class Si {
    public $name;
    public $short_description;
    public $type;
    public $optional = false;
    public $values;
    public $size;
    public $placeholder;
    public $parser;
    public $novalue = false;
    public $nodb = false;
    public $disabled = false;
    public $ifnonempty;
    public $message_default;

    static public $all = [];

    private function store($name, $key, $j, $jkey, $typecheck) {
        if (isset($j->$jkey) && call_user_func($typecheck, $j->$jkey))
            $this->$key = $j->$jkey;
        else if (isset($j->$jkey))
            trigger_error("setting $name.$jkey format error");
    }

    public function __construct($name, $j) {
        $this->name = $name;
        $this->store($name, "short_description", $j, "name", "is_string");
        foreach (["short_description", "type", "parser", "ifnonempty", "message_default", "placeholder"] as $k)
            $this->store($name, $k, $j, $k, "is_string");
        if (!$this->type && $this->parser)
            $this->type = "special";
        foreach (["optional", "novalue", "nodb", "disabled"] as $k)
            $this->store($name, $k, $j, $k, "is_bool");
        $this->store($name, "size", $j, "size", "is_int");
        $this->store($name, "values", $j, "values", "is_array");
    }

    static public function get($name, $k = null) {
        if (!isset(self::$all[$name])
            && ($xname = preg_replace('/_[\$\d]+\z/', '', $name)) !== $name)
            $name = $xname;
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

class SettingValues {
    private $errf = array();
    private $errmsg = array();
    private $warnmsg = array();

    public function __construct() {
    }
    public function has_errors() {
        return count($this->errmsg) > 0;
    }
    public function has_warnings() {
        return count($this->warnmsg) > 0;
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
    public function error_messages() {
        return $this->errmsg;
    }
    public function warning_messages() {
        return $this->warnmsg;
    }

    static public function make_request() {
        global $Conf;
        $sv = new SettingValues;
        $sv->errf = $Conf->session("settings_highlight", array());
        $Conf->save_session("settings_highlight", null);
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
SettingGroup::register("dec", "Decisions", 800, "doDecGroup");

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
            $Group = "dec";
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

    if (!isset($_POST[$name])) {
        $xname = str_replace(".", "_", $name);
        if (isset($_POST[$xname]))
            $_POST[$name] = $_POST[$xname];
        else
            return null;
    }

    $v = trim($_POST[$name]);
    if ($info->placeholder && $info->placeholder === $v)
        $v = "";
    $opt_value = null;
    if (substr($name, 0, 4) === "opt.")
        $opt_value = get($Opt, substr($name, 4));

    if ($info->type === "checkbox")
        return $v != "";
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
                return 0;
        }
        return ($v == "" && !$opt_value ? 0 : array(0, $v));
    } else if ($info->type === "simplestring") {
        $v = simplify_whitespace($v);
        return ($v == "" && !$opt_value ? 0 : array(0, $v));
    } else if ($info->type === "emailheader") {
        $v = MimeText::encode_email_header("", $v);
        if ($v !== false)
            return ($v == "" && !$opt_value ? 0 : array(0, MimeText::decode_header($v)));
        else
            $err = unparse_setting_error($info, "Invalid email header.");
    } else if ($info->type === "emailstring") {
        $v = trim($v);
        if ($v === "" && $info->optional)
            return 0;
        else if (validate_email($v) || $v === $opt_value)
            return ($v == "" ? 0 : array(0, $v));
        else
            $err = unparse_setting_error($info, "Invalid email.");
    } else if ($info->type === "urlstring") {
        $v = trim($v);
        if ($v === "" && $info->optional)
            return 0;
        else if (preg_match(',\A(?:https?|ftp)://\S+\z,', $v))
            return [0, $v];
        else
            $err = unparse_setting_error($info, "Invalid URL.");
    } else if ($info->type === "htmlstring") {
        if (($v = CleanHTML::clean($v, $err)) === false)
            $err = unparse_setting_error($info, $err);
        else if ($info->message_default
                 && $v === $Conf->message_default_html($info->message_default))
            return 0;
        else if ($v === $Conf->setting_data($name))
            return null;
        else
            return ($v == "" ? 0 : array(1, $v));
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

function save_tags($sv, $si_name, $info, $set) {
    global $Conf, $Values;
    $tagger = new Tagger;

    if (!$set && $info->name == "tag_chair" && isset($_POST["tag_chair"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_chair"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                $vs[$t] = true;
            else if ($t !== "")
                $sv->set_error("tag_chair", "Chair-only tag: " . $tagger->error_html);
        $v = array(count($vs), join(" ", array_keys($vs)));
        if (!$sv->has_error("tag_chair")
            && ($Conf->setting("tag_chair") !== $v[0]
                || $Conf->setting_data("tag_chair") !== $v[1]))
            $Values["tag_chair"] = $v;
    }

    if (!$set && $info->name == "tag_vote" && isset($_POST["tag_vote"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_vote"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR)) {
                if (preg_match('/\A([^#]+)(|#|#0+|#-\d*)\z/', $t, $m))
                    $t = $m[1] . "#1";
                $vs[] = $t;
            } else if ($t !== "")
                $sv->set_error("tag_vote", "Allotment voting tag: " . $tagger->error_html);
        $v = array(count($vs), join(" ", $vs));
        if (!$sv->has_error("tag_vote")
            && ($Conf->setting("tag_vote") != $v[0]
                || $Conf->setting_data("tag_vote") !== $v[1]))
            $Values["tag_vote"] = $v;
    }

    if ($set && $info->name == "tag_vote" && isset($Values["tag_vote"])) {
        // check allotments
        $pcm = pcMembers();
        foreach (preg_split('/\s+/', $Values["tag_vote"][1]) as $t) {
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

    if (!$set && $info->name == "tag_approval" && isset($_POST["tag_approval"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_approval"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                $vs[] = $t;
            else if ($t !== "")
                $sv->set_error("tag_approval", "Approval voting tag: " . $tagger->error_html);
        $v = array(count($vs), join(" ", $vs));
        if (!$sv->has_error("tag_approval")
            && ($Conf->setting("tag_approval") != $v[0]
                || $Conf->setting_data("tag_approval") !== $v[1]))
            $Values["tag_approval"] = $v;
    }

    if ($set && $info->name == "tag_approval" && isset($Values["tag_approval"])) {
        $pcm = pcMembers();
        foreach (preg_split('/\s+/', $Values["tag_approval"][1]) as $t) {
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

    if (!$set && $info->name == "tag_rank" && isset($_POST["tag_rank"])) {
        $vs = array();
        foreach (preg_split('/\s+/', $_POST["tag_rank"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                $vs[] = $t;
            else if ($t !== "")
                $sv->set_error("tag_rank", "Rank tag: " . $tagger->error_html);
        if (count($vs) > 1)
            $sv->set_error("tag_rank", "At most one rank tag is currently supported.");
        $v = array(count($vs), join(" ", $vs));
        if (!$sv->has_error("tag_rank")
            && ($Conf->setting("tag_rank") !== $v[0]
                || $Conf->setting_data("tag_rank") !== $v[1]))
            $Values["tag_rank"] = $v;
    }

    if (!$set && $info->name == "tag_color") {
        $vs = array();
        $any_set = false;
        foreach (explode("|", TagInfo::BASIC_COLORS) as $k)
            if (isset($_POST["tag_color_" . $k])) {
                $any_set = true;
                foreach (preg_split('/,*\s+/', $_POST["tag_color_" . $k]) as $t)
                    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $vs[] = $t . "=" . $k;
                    else if ($t !== "")
                        $sv->set_error("tag_color_$k", ucfirst($k) . " style tag: " . $tagger->error_html);
            }
        $v = array(1, join(" ", $vs));
        if ($any_set && $Conf->setting_data("tag_color") !== $v[1])
            $Values["tag_color"] = $v;

        $vs = array();
        $any_set = false;
        foreach (explode("|", TagInfo::BASIC_BADGES) as $k)
            if (isset($_POST["tag_badge_" . $k])) {
                $any_set = true;
                foreach (preg_split('/,*\s+/', $_POST["tag_badge_" . $k]) as $t)
                    if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $vs[] = $t . "=" . $k;
                    else if ($t !== "")
                        $sv->set_error("tag_badge_$k", ucfirst($k) . " badge style tag: " . $tagger->error_html);
            }
        $v = array(1, join(" ", $vs));
        if ($any_set && $Conf->setting_data("tag_badge") !== $v[1])
            $Values["tag_badge"] = $v;
    }

    if (!$set && $info->name == "tag_au_seerev" && isset($_POST["tag_au_seerev"])) {
        $vs = array();
        $chair_tags = array_flip(explode(" ", value_or_setting_data("tag_chair")));
        foreach (preg_split('/,*\s+/', $_POST["tag_au_seerev"]) as $t)
            if ($t !== "" && $tagger->check($t, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $vs[] = $t;
                if (!isset($chair_tags[$t]))
                    $sv->set_warning("tag_au_seerev", "Review visibility tag “" . htmlspecialchars($t) . "” isn’t a <a href=\"" . hoturl("settings", "group=tags") . "\">chair-only tag</a>, which means PC members can change it. You usually want these tags under chair control.");
            } else if ($t !== "")
                $sv->set_error("tag_au_seerev", "Review visibility tag: " . $tagger->error_html);
        $v = array(1, join(" ", $vs));
        if ($v[1] === "")
            $Values["tag_au_seerev"] = null;
        else if ($Conf->setting_data("tag_au_seerev") !== $v[1])
            $Values["tag_au_seerev"] = $v;
    }

    if ($set)
        TagInfo::invalidate_defined_tags();
}

function save_topics($sv, $si_name, $info, $set) {
    global $Conf, $Values;
    if (!$set) {
        $Values["topics"] = true;
        return;
    }

    $tmap = $Conf->topic_map();
    foreach ($_POST as $k => $v)
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


function option_request_to_json($sv, &$new_opts, $id, $current_opts) {
    global $Conf;

    $name = simplify_whitespace(defval($_POST, "optn$id", ""));
    if (!isset($_POST["optn$id"]) && $id[0] !== "n") {
        if (get($current_opts, $id))
            $new_opts[$id] = $current_opts[$id];
        return;
    } else if ($name === ""
               || $_POST["optfp$id"] === "delete"
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

    if (get($_POST, "optd$id") && trim($_POST["optd$id"]) != "") {
        $t = CleanHTML::clean($_POST["optd$id"], $err);
        if ($t !== false)
            $oarg["description"] = $t;
        else
            $sv->set_error("optd$id", $err);
    }

    if (($optvt = get($_POST, "optvt$id"))) {
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
        $seltext = trim(cleannl(defval($_POST, "optv$id", "")));
        if ($seltext != "") {
            foreach (explode("\n", $seltext) as $t)
                $oarg["selector"][] = $t;
        } else
            $sv->set_error("optv$id", "Enter selectors one per line.");
    }

    $oarg["visibility"] = defval($_POST, "optp$id", "rev");
    if ($oarg["final"])
        $oarg["visibility"] = "rev";

    $oarg["position"] = (int) defval($_POST, "optfp$id", 1);

    $oarg["display"] = defval($_POST, "optdt$id");
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

function save_options($sv, $si_name, $info, $set) {
    global $Conf, $Values;

    if (!$set) {
        $current_opts = PaperOption::option_list();

        // convert request to JSON
        $new_opts = array();
        foreach ($current_opts as $id => $o)
            option_request_to_json($sv, $new_opts, $id, $current_opts);
        foreach ($_POST as $k => $v)
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

        if (!$sv->has_errors())
            $Values["options"] = $new_opts;
        return;
    }

    $new_opts = $Values["options"];
    $current_opts = PaperOption::option_list();
    option_clean_form_positions($new_opts, $current_opts);

    $newj = (object) array();
    uasort($new_opts, array("PaperOption", "compare"));
    $nextid = $Conf->setting("next_optionid", 1);
    foreach ($new_opts as $id => $o) {
        $newj->$id = $o->unparse();
        $nextid = max($nextid, $id + 1);
    }
    $Conf->save_setting("next_optionid", $nextid);
    $Conf->save_setting("options", 1, count($newj) ? $newj : null);

    // warn on visibility
    if (value_or_setting("sub_blind") === Conf::BLIND_ALWAYS) {
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

function save_decisions($sv, $si_name, $info, $set) {
    global $Conf, $Values;
    if (!$set) {
        $dec_revmap = array();
        foreach ($_POST as $k => &$dname)
            if (str_starts_with($k, "dec")
                && ($k === "decn" || ($dnum = cvtint(substr($k, 3), 0)))
                && ($k !== "decn" || trim($dname) !== "")) {
                $dname = simplify_whitespace($dname);
                if (($derror = Conf::decision_name_error($dname)))
                    $sv->set_error($k, htmlspecialchars($derror));
                else if (isset($dec_revmap[strtolower($dname)]))
                    $sv->set_error($k, "Decision name “{$dname}” was already used.");
                else
                    $dec_revmap[strtolower($dname)] = true;
            }
        unset($dname);

        if (get($_POST, "decn") && !get($_POST, "decn_confirm")) {
            $delta = (defval($_POST, "dtypn", 1) > 0 ? 1 : -1);
            $match_accept = (stripos($_POST["decn"], "accept") !== false);
            $match_reject = (stripos($_POST["decn"], "reject") !== false);
            if ($delta > 0 && $match_reject)
                $sv->set_error("decn", "You are trying to add an Accept-class decision that has “reject” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
            else if ($delta < 0 && $match_accept)
                $sv->set_error("decn", "You are trying to add a Reject-class decision that has “accept” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
        }

        $Values["decisions"] = true;
        return;
    }

    // mark all used decisions
    $decs = $Conf->decision_map();
    $update = false;
    foreach ($_POST as $k => $v)
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

    if (defval($_POST, "decn", "") != "") {
        $delta = (defval($_POST, "dtypn", 1) > 0 ? 1 : -1);
        for ($k = $delta; isset($decs[$k]); $k += $delta)
            /* skip */;
        $decs[$k] = $_POST["decn"];
        $update = true;
    }

    if ($update)
        $Conf->save_setting("outcome_map", 1, $decs);
}

function save_banal($sv, $si_name, $info, $set) {
    global $Conf, $Values, $ConfSitePATH;
    if ($set)
        return true;
    if (!isset($_POST["sub_banal"])) {
        if (($t = $Conf->setting_data("sub_banal", "")) != "")
            $Values["sub_banal"] = array(0, $t);
        else
            $Values["sub_banal"] = null;
        return true;
    }

    // check banal subsettings
    $old_error_count = $sv->error_count();
    $bs = array_fill(0, 6, "");
    if (($s = trim(defval($_POST, "sub_banal_papersize", ""))) != ""
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

    if (($s = trim(defval($_POST, "sub_banal_pagelimit", ""))) != ""
        && strcasecmp($s, "N/A") != 0) {
        if (($sx = cvtint($s, -1)) > 0)
            $bs[1] = $sx;
        else if (preg_match('/\A(\d+)\s*-\s*(\d+)\z/', $s, $m)
                 && $m[1] > 0 && $m[2] > 0 && $m[1] <= $m[2])
            $bs[1] = +$m[1] . "-" . +$m[2];
        else
            $sv->set_error("sub_banal_pagelimit", "Page limit must be a whole number bigger than 0, or a page range such as <code>2-4</code>.");
    }

    if (($s = trim(defval($_POST, "sub_banal_columns", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (($sx = cvtint($s, -1)) >= 0)
            $bs[2] = ($sx > 0 ? $sx : $bs[2]);
        else
            $sv->set_error("sub_banal_columns", "Columns must be a whole number.");
    }

    if (($s = trim(defval($_POST, "sub_banal_textblock", ""))) != ""
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

    if (($s = trim(defval($_POST, "sub_banal_bodyfontsize", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (!is_numeric($s) || $s <= 0)
            $sv->error("sub_banal_bodyfontsize", "Minimum body font size must be a number bigger than 0.");
        else
            $bs[4] = $s;
    }

    if (($s = trim(defval($_POST, "sub_banal_bodyleading", ""))) != ""
        && strcasecmp($s, "any") != 0 && strcasecmp($s, "N/A") != 0) {
        if (!is_numeric($s) || $s <= 0)
            $sv->error("sub_banal_bodyleading", "Minimum body leading must be a number bigger than 0.");
        else
            $bs[5] = $s;
    }

    while (count($bs) > 0 && $bs[count($bs) - 1] == "")
        array_pop($bs);

    // actually create setting
    if ($sv->error_count() == $old_error_count) {
        $Values["sub_banal"] = array(1, join(";", $bs));
        $zoomarg = "";

        // Perhaps we have an old pdftohtml with a bad -zoom.
        for ($tries = 0; $tries < 2; ++$tries) {
            $cf = new CheckFormat();
            $s1 = $cf->analyzeFile("$ConfSitePATH/src/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
            $e1 = $cf->errors;
            if ($s1 == 1 && ($e1 & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
                $zoomarg = ">-zoom=1";
            else if ($s1 != 2 && $tries == 1)
                $zoomarg = "";
        }

        $Values["sub_banal"][1] .= $zoomarg;
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
    }
}

function save_tracks($sv, $si_name, $info, $set) {
    global $Values;
    if ($set)
        return;
    $tagger = new Tagger;
    $tracks = (object) array();
    $missing_tags = false;
    for ($i = 1; isset($_POST["name_track$i"]); ++$i) {
        $trackname = trim($_POST["name_track$i"]);
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
        foreach (array("view", "viewpdf", "viewrev", "assrev", "unassrev",
                       "viewtracker") as $type)
            if (($ttype = defval($_POST, "${type}_track$i", "")) == "+"
                || $ttype == "-") {
                $ttag = trim(defval($_POST, "${type}tag_track$i", ""));
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
    if (count((array) $tracks))
        $Values["tracks"] = array(1, json_encode($tracks));
    else
        $Values["tracks"] = null;
}

function save_rounds($sv, $si_name, $info, $set) {
    global $Conf, $Values;
    if ($set)
        return;
    else if (!isset($_POST["rev_roundtag"])) {
        $Values["rev_roundtag"] = null;
        return;
    }
    // round names
    $roundnames = $roundnames_set = array();
    $roundname0 = $round_deleted = null;
    $Values["rev_round_changes"] = array();
    for ($i = 0;
         isset($_POST["roundname_$i"]) || isset($_POST["deleteround_$i"]) || !$i;
         ++$i) {
        $rname = trim(get_s($_POST, "roundname_$i"));
        if ($rname === "(no name)" || $rname === "default" || $rname === "unnamed")
            $rname = "";
        if ((get($_POST, "deleteround_$i") || $rname === "") && $i) {
            $roundnames[] = ";";
            $Values["rev_round_changes"][] = array($i, 0);
            if ($round_deleted === null && !isset($_POST["roundname_0"])
                && $i < $_POST["oldroundcount"])
                $round_deleted = $i;
        } else if ($rname === "")
            /* ignore */;
        else if (($rerror = Conf::round_name_error($rname)))
            $sv->set_error("roundname_$i", $rerror);
        else if ($i == 0)
            $roundname0 = $rname;
        else if (get($roundnames_set, strtolower($rname))) {
            $roundnames[] = ";";
            $Values["rev_round_changes"][] = array($i, $roundnames_set[strtolower($rname)]);
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
        array_unshift($Values["rev_round_changes"], array(0, $roundnames_set[strtolower($roundname0)]));

    // round deadlines
    foreach ($Conf->round_list() as $i => $rname) {
        $suffix = $i ? "_$i" : "";
        foreach (Conf::$review_deadlines as $k)
            $Values[$k . $suffix] = null;
    }
    $rtransform = array();
    if ($roundname0 && ($ri = $roundnames_set[strtolower($roundname0)])
        && !isset($_POST["pcrev_soft_$ri"])) {
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
             || ($i ? $roundnames[$i - 1] !== ";" : !isset($_POST["deleteround_0"])))
            && get($rtransform, $i) !== false) {
            $isuffix = $i ? "_$i" : "";
            if (($osuffix = get($rtransform, $i)) === null)
                $osuffix = $isuffix;
            $ndeadlines = 0;
            foreach (Conf::$review_deadlines as $k) {
                $v = parse_value($sv, $k . $isuffix, Si::get($k));
                $Values[$k . $osuffix] = $v < 0 ? null : $v;
                $ndeadlines += $v > 0;
            }
            if ($ndeadlines == 0 && $osuffix)
                $Values["pcrev_soft$osuffix"] = 0;
            foreach (array("pcrev_", "extrev_") as $k) {
                list($soft, $hard) = array("{$k}soft$osuffix", "{$k}hard$osuffix");
                if (!get($Values, $soft) && get($Values, $hard))
                    $Values[$soft] = $Values[$hard];
                else if (get($Values, $hard) && get($Values, $soft) > $Values[$hard]) {
                    $desc = $i ? ", round " . htmlspecialchars($roundnames[$i - 1]) : "";
                    $sv->set_error($soft, Si::get("{$k}soft", "name") . $desc . ": Must come before " . Si::get("{$k}hard", "name") . ".");
                    $sv->set_error($hard);
                }
            }
        }

    // round list (save after deadlines processing)
    while (count($roundnames) && $roundnames[count($roundnames) - 1] === ";")
        array_pop($roundnames);
    if (count($roundnames))
        $Values["tag_rounds"] = array(1, join(" ", $roundnames));
    else
        $Values["tag_rounds"] = null;

    // default round
    $t = trim($_POST["rev_roundtag"]);
    $Values["rev_roundtag"] = null;
    if (preg_match('/\A(?:|\(none\)|\(no name\)|default|unnamed)\z/i', $t))
        /* do nothing */;
    else if ($t === "#0") {
        if ($roundname0)
            $Values["rev_roundtag"] = array(1, $roundname0);
    } else if (preg_match('/^#[1-9][0-9]*$/', $t)) {
        $rname = get($roundnames, substr($t, 1) - 1);
        if ($rname && $rname !== ";")
            $Values["rev_roundtag"] = array(1, $rname);
    } else if (!($rerror = Conf::round_name_error($t)))
        $Values["rev_roundtag"] = array(1, $t);
    else
        $sv->set_error("rev_roundtag", $rerror);
}

function save_resp_rounds($sv, $si_name, $info, $set) {
    global $Conf, $Values;
    if ($set || !value_or_setting("resp_active"))
        return;
    $old_roundnames = $Conf->resp_round_list();
    $roundnames = array(1);
    $roundnames_set = array();

    if (isset($_POST["resp_roundname"])) {
        $rname = trim(get_s($_POST, "resp_roundname"));
        if ($rname === "" || $rname === "none" || $rname === "1")
            /* do nothing */;
        else if (($rerror = Conf::resp_round_name_error($rname)))
            $sv->set_error("resp_roundname", $rerror);
        else {
            $roundnames[0] = $rname;
            $roundnames_set[strtolower($rname)] = 0;
        }
    }

    for ($i = 1; isset($_POST["resp_roundname_$i"]); ++$i) {
        $rname = trim(get_s($_POST, "resp_roundname_$i"));
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
            $Values["resp_open$isuf"] = $v < 0 ? null : $v;
        if (($v = parse_value($sv, "resp_done$isuf", Si::get("resp_done"))) !== null)
            $Values["resp_done$isuf"] = $v < 0 ? null : $v;
        if (($v = parse_value($sv, "resp_words$isuf", Si::get("resp_words"))) !== null)
            $Values["resp_words$isuf"] = $v < 0 ? null : $v;
        if (($v = parse_value($sv, "msg.resp_instrux$isuf", Si::get("msg.resp_instrux"))) !== null)
            $Values["msg.resp_instrux$isuf"] = $v;
    }

    if (count($roundnames) > 1 || $roundnames[0] !== 1)
        $Values["resp_rounds"] = array(1, join(" ", $roundnames));
    else
        $Values["resp_rounds"] = 0;
}

function save_review_form($sv, $si_name, $info, $set) {
    global $Values;
    if (!$set)
        $Values[$info->name] = true;
    else
        rf_update($sv);
}

function truthy($x) {
    return !($x === null || $x === 0 || $x === false
             || $x === "" || $x === "0" || $x === "false");
}

function account_value($sv, $info) {
    global $Values;
    $xname = str_replace(".", "_", $info->name);
    if ($info->type === "special")
        $has_value = truthy(get($_POST, "has_$xname"));
    else
        $has_value = isset($_POST[$xname])
            || (($info->type === "cdate" || $info->type === "checkbox")
                && truthy(get($_POST, "has_$xname")));

    if ($has_value && ($info->disabled || $info->novalue
                       || !$info->type || $info->type === "none"))
        /* ignore changes to disabled/novalue settings */;
    else if ($has_value && $info->type === "special")
        call_user_func($info->parser, $sv, $info->name, $info, false);
    else if ($has_value) {
        $v = parse_value($sv, $info->name, $info);
        if ($v === null) {
            if ($info->type !== "cdate" && $info->type !== "checkbox")
                return;
            $v = 0;
        }
        if (!is_array($v) && $v <= 0 && $info->type !== "radio" && $info->type !== "zint")
            $Values[$info->name] = null;
        else
            $Values[$info->name] = $v;
        if ($info->ifnonempty)
            $Values[$info->ifnonempty] = ($Values[$info->name] === null ? null : 1);
    }
}

function has_value($name) {
    global $Values;
    return array_key_exists($name, $Values);
}

function value($name, $default = null) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return $Values[$name];
    else
        return $default;
}

function value_or_setting($name) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return $Values[$name];
    else
        return $Conf->setting($name);
}

function value_or_setting_data($name) {
    global $Conf, $Values;
    if (array_key_exists($name, $Values))
        return is_array(get($Values, $name)) ? $Values[$name][1] : null;
    else
        return $Conf->setting_data($name);
}

if (isset($_REQUEST["update"]) && check_post()) {
    // parse settings
    foreach (Si::$all as $tag => $si)
        account_value($Sv, $si);

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "final_soft" => "final_done")
             as $first => $second)
        if (!get($Values, $first) && get($Values, $second))
            $Values[$first] = $Values[$second];
        else if (get($Values, $second) && get($Values, $first) > $Values[$second]) {
            $Sv->set_error($first, unparse_setting_error(Si::get($first), "Must come before " . Si::get($second, "name") . "."));
            $Sv->set_error($second);
        }
    if (array_key_exists("sub_sub", $Values))
        $Values["sub_update"] = $Values["sub_sub"];
    if (get($Opt, "defaultSiteContact")) {
        if (array_key_exists("opt.contactName", $Values)
            && get($Opt, "contactName") === $Values["opt.contactName"][1])
            $Values["opt.contactName"] = null;
        if (array_key_exists("opt.contactEmail", $Values)
            && get($Opt, "contactEmail") === $Values["opt.contactEmail"][1])
            $Values["opt.contactEmail"] = null;
    }
    if (get($Values, "resp_active"))
        foreach (explode(" ", value_or_setting_data("resp_rounds")) as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            if (get($Values, "resp_open$isuf") && get($Values, "resp_done$isuf")
                && $Values["resp_open$isuf"] > $Values["resp_done$isuf"]) {
                $Sv->set_error("resp_open$isuf", unparse_setting_error(Si::get("resp_open"), "Must come before " . Si::get("resp_done", "name") . "."));
                $Sv->set_error("resp_done$isuf");
            }
        }

    // update 'papersub'
    if (isset($_POST["pc_seeall"])) {
        // see also conference.php
        $result = $Conf->q("select ifnull(min(paperId),0) from Paper where " . (defval($Values, "pc_seeall", 0) <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0"));
        if (($row = edb_row($result)) && $row[0] != $Conf->setting("papersub"))
            $Values["papersub"] = $row[0];
    }

    // warn on other relationships
    if (value("sub_freeze", -1) == 0
        && value("sub_open") > 0
        && value("sub_sub") <= 0)
        $Sv->set_warning(null, "You have not set a paper submission deadline, but authors can update their submissions until the deadline.  This is sometimes unintentional.  You probably should (1) specify a paper submission deadline; (2) select “Authors must freeze the final version of each submission”; or (3) manually turn off “Open site for submissions” when submissions complete.");
    if (value("sub_open", 1) <= 0
        && $Conf->setting("sub_open") > 0
        && value_or_setting("sub_sub") <= 0)
        $Values["sub_close"] = $Now;
    foreach (array("pcrev_soft", "pcrev_hard", "extrev_soft", "extrev_hard")
             as $deadline)
        if (value($deadline) > $Now
            && value($deadline) != $Conf->setting($deadline)
            && value_or_setting("rev_open") <= 0) {
            $Sv->set_warning("rev_open", "Review deadline set. You may also want to open the site for reviewing.");
            break;
        }
    if (value_or_setting("au_seerev") != Conf::AUSEEREV_NO
        && value_or_setting("au_seerev") != Conf::AUSEEREV_TAGS
        && $Conf->setting("pcrev_soft") > 0
        && $Now < $Conf->setting("pcrev_soft")
        && !$Sv->has_errors())
        $Sv->set_warning(null, "Authors can now see reviews and comments although it is before the review deadline.  This is sometimes unintentional.");
    if (value("final_open")
        && (!value("final_done") || value("final_done") > $Now)
        && value_or_setting("seedec") != Conf::SEEDEC_ALL)
        $Sv->set_warning(null, "The system is set to collect final versions, but authors cannot submit final versions until they know their papers have been accepted.  You should change the “Who can see paper decisions” setting to “<strong>Authors</strong>, etc.”");
    if (value("seedec") == Conf::SEEDEC_ALL
        && value_or_setting("au_seerev") == Conf::AUSEEREV_NO)
        $Sv->set_warning(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");
    if (has_value("msg.clickthrough_submit"))
        $Values["clickthrough_submit"] = null;
    if (value_or_setting("au_seerev") == Conf::AUSEEREV_TAGS
        && !value_or_setting_data("tag_au_seerev")
        && !$Sv->has_error("tag_au_seerev"))
        $Sv->set_warning("tag_au_seerev", "You haven’t set any review visibility tags.");
    if (has_value("sub_nopapers"))
        $Values["opt.noPapers"] = value("sub_nopapers") ? : null;
    if (has_value("sub_noabstract"))
        $Values["opt.noAbstract"] = value("sub_noabstract") ? : null;

    // make settings
    if (!$Sv->has_errors() && count($Values) > 0) {
        $tables = "Settings write, TopicArea write, PaperTopic write, TopicInterest write, PaperOption write";
        if (array_key_exists("decisions", $Values)
            || array_key_exists("tag_vote", $Values)
            || array_key_exists("tag_approval", $Values))
            $tables .= ", Paper write";
        if (array_key_exists("tag_vote", $Values)
            || array_key_exists("tag_approval", $Values))
            $tables .= ", PaperTag write";
        if (array_key_exists("reviewform", $Values))
            $tables .= ", PaperReview write";
        $Conf->qe("lock tables $tables");

        // apply settings
        foreach ($Values as $n => $v) {
            $si = Si::get($n);
            if ($si && $si->type == "special")
                call_user_func($si->parser, $Sv, $n, $si, true);
        }

        $dv = $aq = $av = array();
        foreach ($Values as $n => $v)
            if (!Si::get($n, "nodb")) {
                $dv[] = $n;
                if (substr($n, 0, 4) === "opt.") {
                    $okey = substr($n, 4);
                    $oldv = (array_key_exists($okey, $OptOverride) ? $OptOverride[$okey] : get($Opt, $okey));
                    $Opt[$okey] = (is_array($v) ? $v[1] : $v);
                    if ($oldv === $Opt[$okey])
                        continue; // do not save value in database
                    else if (!array_key_exists($okey, $OptOverride))
                        $OptOverride[$okey] = $oldv;
                }
                if (is_array($v)) {
                    $aq[] = "(?, ?, ?)";
                    array_push($av, $n, $v[0], $v[1]);
                } else if ($v !== null) {
                    $aq[] = "(?, ?, null)";
                    array_push($av, $n, $v);
                }
            }
        if (count($dv))
            Dbl::qe_apply("delete from Settings where name?a", array($dv));
        if (count($aq))
            Dbl::qe_apply("insert into Settings (name, value, data) values " . join(",", $aq), $av);

        $Conf->qe("unlock tables");
        $Me->log_activity("Updated settings group '$Group'");
        $Conf->load_settings();

        // remove references to deleted rounds
        if (array_key_exists("rev_round_changes", $Values))
            foreach ($Values["rev_round_changes"] as $x)
                $Conf->qe("update PaperReview set reviewRound=$x[1] where reviewRound=$x[0]");

        // contactdb may need to hear about changes to shortName
        if (array_key_exists("opt.shortName", $Values)
            && get($Opt, "contactdb_dsn") && ($cdb = Contact::contactdb()))
            Dbl::ql($cdb, "update Conferences set shortName=? where dbName=?", $Opt["shortName"], $Opt["dbName"]);
    }

    // report errors
    $msgs = array();
    if ($Sv->has_errors() || $Sv->has_warnings()) {
        $any_errors = false;
        foreach ($Sv->error_messages() as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = $any_errors = $m;
        foreach ($Sv->warning_messages() as $m)
            if ($m && $m !== true && $m !== 1)
                $msgs[] = "Warning: " . $m;
        $mt = '<div class="multimessage"><div>' . join('</div><div>', $msgs) . '</div></div>';
        if (count($msgs) && $any_errors)
            Conf::msg_error($mt);
        else if (count($msgs))
            $Conf->warnMsg($mt);
    }

    // update the review form in case it's changed
    ReviewForm::clear_cache();
    if (!$Sv->has_errors()) {
        $Conf->save_session("settings_highlight", $Sv->error_fields());
        if (!count($msgs))
            $Conf->confirmMsg("Changes saved.");
        redirectSelf();
    }
}
if (isset($_REQUEST["cancel"]) && check_post())
    redirectSelf();


function setting_js($name, $extra = array()) {
    global $Sv;
    $x = array("id" => $name);
    if (Si::get($name, "disabled"))
        $x["disabled"] = true;
    foreach ($extra as $k => $v)
        $x[$k] = $v;
    if ($Sv->has_error($name))
        $x["class"] = trim("setting_error " . (get($x, "class") ? : ""));
    return $x;
}

function setting_class($name) {
    global $Sv;
    return $Sv->has_error($name) ? "setting_error" : null;
}

function setting_label($name, $text, $label = null) {
    global $Sv;
    $name1 = is_array($name) ? $name[0] : $name;
    foreach (is_array($name) ? $name : array($name) as $n)
        if ($Sv->has_error($n)) {
            $text = '<span class="setting_error">' . $text . '</span>';
            break;
        }
    if ($label !== false)
        $text = Ht::label($text, $label ? : $name1);
    return $text;
}

function xsetting($name, $defval = null) {
    global $Conf, $Sv;
    if ($Sv->has_errors())
        return defval($_POST, $name, $defval);
    else
        return $Conf->setting($name, $defval);
}

function xsetting_data($name, $defval = "", $killval = "") {
    global $Conf, $Sv;
    if (substr($name, 0, 4) === "opt.")
        return opt_data(substr($name, 4), $defval, $killval);
    else if ($Sv->has_errors())
        $val = defval($_POST, $name, $defval);
    else
        $val = defval($Conf->settingTexts, $name, $defval);
    if ($val == $killval)
        $val = "";
    return $val;
}

function opt_data($name, $defval = "", $killval = "") {
    global $Opt, $Sv;
    if ($Sv->has_errors())
        $val = defval($_POST, "opt.$name", $defval);
    else
        $val = defval($Opt, $name, $defval);
    if ($val == $killval)
        $val = "";
    return $val;
}

function doCheckbox($name, $text, $tr = false, $js = null) {
    $x = xsetting($name);
    echo ($tr ? '<tr><td class="nw">' : ""),
        Ht::hidden("has_$name", 1),
        Ht::checkbox($name, 1, $x !== null && $x > 0, setting_js($name, array("onchange" => $js, "id" => "cb$name"))),
        "&nbsp;", ($tr ? "</td><td>" : ""),
        setting_label($name, $text, true),
        ($tr ? "</td></tr>\n" : "<br />\n");
}

function doRadio($name, $varr) {
    $x = xsetting($name);
    if ($x === null || !isset($varr[$x]))
        $x = 0;
    echo "<table style=\"margin-top:0.25em\">\n";
    foreach ($varr as $k => $text) {
        echo '<tr><td class="nw">', Ht::radio($name, $k, $k == $x, setting_js($name, array("id" => "{$name}_{$k}"))),
            "&nbsp;</td><td>";
        if (is_array($text))
            echo setting_label($name, $text[0], true), "<br /><small>", $text[1], "</small>";
        else
            echo setting_label($name, $text, true);
        echo "</td></tr>\n";
    }
    echo "</table>\n";
}

function render_entry($name, $v, $size = 30, $temptext = "") {
    $js = ["size" => $size, "placeholder" => $temptext];
    if (($info = Si::get($name))) {
        if ($info->size)
            $js["size"] = $info->size;
        if ($info->placeholder)
            $js["placeholder"] = $info->placeholder;
    }
    return Ht::entry($name, $v, setting_js($name, $js));
}

function doTextRow($name, $text, $v, $size = 30,
                   $capclass = "lcaption", $tempText = "") {
    global $Conf;
    $nametext = (is_array($text) ? $text[0] : $text);
    echo '<tr><td class="', $capclass, ' nw">', setting_label($name, $nametext),
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
    $x = xsetting($name);
    if ($x !== null && $Sv->has_errors())
        return $x;
    if ($othername && xsetting($othername) == $x)
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
    global $GraceExplanation;
    if (!isset($GraceExplanation)) {
        $text = array($text, "Example: “15 min”");
        $GraceExplanation = true;
    }
    doTextRow($name, $text, unparseGrace(xsetting($name)), 15, $capclass, "none");
}

function doActionArea($top) {
    echo "<div class='aa'>",
        Ht::submit("update", "Save changes", array("class" => "bb")),
        " &nbsp;", Ht::submit("cancel", "Cancel"), "</div>";
}



// Accounts
function doAccGroup($sv) {
    global $Conf, $Me;

    if (xsetting("acct_addr"))
        doCheckbox("acct_addr", "Collect users’ addresses and phone numbers");

    echo "<h3 class=\"settings g\">Program committee &amp; system administrators</h3>";

    echo "<p><a href='", hoturl("profile", "u=new&amp;role=pc"), "' class='button'>Create PC account</a> &nbsp;|&nbsp; ",
        "Select a user’s name to edit a profile.</p>\n";
    $pl = new ContactList($Me, false);
    echo $pl->table_html("pcadminx", hoturl("users", "t=pcadmin"));
}

// Messages
function do_message($name, $description, $type, $rows = 10, $hint = "") {
    global $Conf;
    $defaultname = $name;
    if (is_array($name))
        list($name, $defaultname) = $name;
    $default = $Conf->message_default_html($defaultname);
    $current = xsetting_data($name, $default);
    echo '<div class="fold', ($current == $default ? "c" : "o"),
        '" data-fold="true">',
        '<div class="', ($type ? "f-cn" : "f-cl"),
        ' childfold" onclick="return foldup(this,event)">',
        '<a class="q" href="#" onclick="return foldup(this,event)">',
        expander(null, 0), setting_label($name, $description),
        '</a> <span class="f-cx fx">(HTML allowed)</span></div>',
        $hint,
        Ht::textarea($name, $current, setting_js($name, array("class" => "fx", "rows" => $rows, "cols" => 80))),
        '</div><div class="g"></div>', "\n";
}

function doInfoGroup($sv) {
    global $Conf, $Opt;

    echo '<div class="f-c">', setting_label("opt.shortName", "Conference abbreviation"), "</div>\n";
    doEntry("opt.shortName", opt_data("shortName"), 20);
    echo '<div class="f-h">Examples: “HotOS XIV”, “NSDI \'14”</div>';
    echo "<div class=\"g\"></div>\n";

    $long = opt_data("longName");
    if ($long == opt_data("shortName"))
        $long = "";
    echo "<div class='f-c'>", setting_label("opt.longName", "Conference name"), "</div>\n";
    doEntry("opt.longName", $long, 70);
    echo '<div class="f-h">Example: “14th Workshop on Hot Topics in Operating Systems”</div>';
    echo "<div class=\"g\"></div>\n";

    echo "<div class='f-c'>", setting_label("opt.conferenceSite", "Conference URL"), "</div>\n";
    doEntry("opt.conferenceSite", opt_data("conferenceSite"), 70);
    echo '<div class="f-h">Example: “http://yourconference.org/”</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.contactName", "Name of site contact"), "</div>\n";
    doEntry("opt.contactName", opt_data("contactName", null, "Your Name"), 50);
    echo '<div class="g"></div>', "\n";

    echo "<div class='f-c'>", setting_label("opt.contactEmail", "Email of site contact"), "</div>\n";
    doEntry("opt.contactEmail", opt_data("contactEmail", null, "you@example.com"), 40);
    echo '<div class="f-h">The site contact is the contact point for users if something goes wrong. It defaults to the chair.</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.emailReplyTo", "Reply-To field for email"), "</div>\n";
    doEntry("opt.emailReplyTo", opt_data("emailReplyTo"), 80);
    echo '<div class="g"></div>', "\n";

    echo '<div class="f-c">', setting_label("opt.emailCc", "Default Cc for reviewer email"), "</div>\n";
    doEntry("opt.emailCc", opt_data("emailCc"), 80);
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

    doCheckbox('sub_open', '<b>Open site for submissions</b>');

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
    doCheckbox('pc_seeall', "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences before most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>", true);
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

    if ($sv->has_errors() && isset($_POST["optn$id"])) {
        $o = PaperOption::make(array("id" => $id,
                "name" => $_POST["optn$id"],
                "description" => get($_POST, "optd$id"),
                "type" => get($_POST, "optvt$id", "checkbox"),
                "visibility" => get($_POST, "optp$id", ""),
                "position" => get($_POST, "optfp$id", 1),
                "display" => get($_POST, "optdt$id")));
        if ($o->has_selector())
            $o->selector = explode("\n", rtrim(defval($_POST, "optv$id", "")));
    }

    echo "<tr><td><div class='f-contain'>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        setting_label("optn$id", ($id === "n" ? "New option name" : "Option name")),
        "</div>",
        "<div class='f-e'>",
        Ht::entry("optn$id", $o->name, setting_js("optn$id", array("placeholder" => "(Enter new option)", "size" => 50))),
        "</div>\n",
        "  <div class='f-i'>",
        "<div class='f-c'>",
        setting_label("optd$id", "Description"),
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
        setting_label("optvt$id", "Type"), "</div><div class='f-e'>";

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
        setting_label("optp$id", "Visibility"), "</div><div class='f-e'>",
        Ht::select("optp$id", array("admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"), $o->visibility, array("id" => "optp$id")),
        "</div></div></td>";

    echo "<td class='pad'><div class='f-i'><div class='f-c'>",
        setting_label("optfp$id", "Form order"), "</div><div class='f-e'>";
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
        setting_label("optdt$id", "Display"), "</div><div class='f-e'>";
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
        Ht::textarea("optv$id", $value, setting_js("optv$id", array("rows" => $rows, "cols" => 50))),
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
            Ht::hidden("has_banal", 1),
            "<table id='foldbanal' class='", (xsetting("sub_banal") ? "foldo" : "foldc"), "'>";
        doCheckbox("sub_banal", "PDF format checker<span class='fx'>:</span>", true, "void fold('banal',!this.checked)");
        echo "<tr class='fx'><td></td><td class='top'><table>";
        $bsetting = explode(";", preg_replace("/>.*/", "", $Conf->setting_data("sub_banal", "")));
        for ($i = 0; $i < 6; $i++)
            if (defval($bsetting, $i, "") == "")
                $bsetting[$i] = "N/A";
        doTextRow("sub_banal_papersize", array("Paper size", "Examples: “letter”, “A4”, “8.5in&nbsp;x&nbsp;14in”,<br />“letter OR A4”"), xsetting("sub_banal_papersize", $bsetting[0]), 18, "lxcaption", "N/A");
        doTextRow("sub_banal_pagelimit", "Page limit", xsetting("sub_banal_pagelimit", $bsetting[1]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_textblock", array("Text block", "Examples: “6.5in&nbsp;x&nbsp;9in”, “1in&nbsp;margins”"), xsetting("sub_banal_textblock", $bsetting[3]), 18, "lxcaption", "N/A");
        echo "</table></td><td><span class='sep'></span></td><td class='top'><table>";
        doTextRow("sub_banal_bodyfontsize", array("Minimum body font size", null, "&nbsp;pt"), xsetting("sub_banal_bodyfontsize", $bsetting[4]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_bodyleading", array("Minimum leading", null, "&nbsp;pt"), xsetting("sub_banal_bodyleading", $bsetting[5]), 4, "lxcaption", "N/A");
        doTextRow("sub_banal_columns", array("Columns", null), xsetting("sub_banal_columns", $bsetting[2]), 4, "lxcaption", "N/A");
        echo "</table></td></tr></table>";
    }

    echo "<h3 class=\"settings\">Conflicts &amp; collaborators</h3>\n",
        "<table id=\"foldpcconf\" class=\"fold",
        (xsetting("sub_pcconf") ? "o" : "c"), "\">\n";
    doCheckbox("sub_pcconf", "Collect authors’ PC conflicts", true,
               "void fold('pcconf',!this.checked)");
    echo "<tr class='fx'><td></td><td>";
    $conf = array();
    foreach (Conflict::$type_descriptions as $n => $d)
        if ($n)
            $conf[] = "“{$d}”";
    doCheckbox("sub_pcconfsel", "Require conflict descriptions (" . commajoin($conf, "or") . ")");
    echo "</td></tr>\n";
    doCheckbox("sub_collab", "Collect authors’ other collaborators as text", true);
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
        if ($sv->has_errors() && isset($_POST["top$tid"]))
            $tname = $_POST["top$tid"];
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
        Ht::textarea("topnew", $sv->has_errors() ? get($_POST, "topnew") : "", array("cols" => 40, "rows" => 2, "style" => "width:20em")),
        '</td></tr></table>';
}

// Reviews
function echo_round($sv, $rnum, $nameval, $review_count, $deletable) {
    global $Conf;
    $rname = "roundname_$rnum";
    if ($sv->has_errors() && $rnum !== '$')
        $nameval = (string) get($_POST, $rname);

    $default_rname = "unnamed";
    if ($nameval === "(new round)" || $rnum === '$')
        $default_rname = "(new round)";
    echo '<div class="mg" data-round-number="', $rnum, '"><div>',
        setting_label($rname, "Round"), ' &nbsp;',
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
    echo '<tr><td>', setting_label("pcrev_soft$entrysuf", "PC deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("pcrev_soft$entrysuf", date_value("pcrev_soft$dlsuf", "none"), 28, "none"),
        '</td><td class="lentry">', setting_label("pcrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("pcrev_hard$entrysuf", date_value("pcrev_hard$dlsuf", "none"), 28, "none"),
        '</td></tr>';
    echo '<tr><td>', setting_label("extrev_soft$entrysuf", "External deadline"), ' &nbsp;</td>',
        '<td class="lentry" style="padding-right:3em">',
        render_entry("extrev_soft$entrysuf", date_value("extrev_soft$dlsuf", "same as PC", "pcrev_soft$dlsuf"), 28, "same as PC"),
        '</td><td class="lentry">', setting_label("extrev_hard$entrysuf", "Hard deadline"), ' &nbsp;</td><td>',
        render_entry("extrev_hard$entrysuf", date_value("extrev_hard$dlsuf", "same as PC", "pcrev_hard$dlsuf"), 28, "same as PC"),
        '</td></tr>';
    echo '</table></div>', "\n";
}

function doRevGroup($sv) {
    global $Conf, $DateExplanation;

    doCheckbox("rev_open", "<b>Open site for reviewing</b>");
    doCheckbox("cmt_always", "Allow comments even if reviewing is closed");

    echo "<div class='g'></div>\n";
    doCheckbox('pcrev_any', "PC members can review <strong>any</strong> submitted paper");

    echo "<div class='g'></div>\n";
    echo "<strong>Review anonymity:</strong> Are reviewer names hidden from authors?<br />\n";
    doRadio("rev_blind", array(Conf::BLIND_ALWAYS => "Yes—reviews are anonymous",
                               Conf::BLIND_NEVER => "No—reviewer names are visible to authors",
                               Conf::BLIND_OPTIONAL => "Depends—reviewers decide whether to expose their names"));

    echo "<div class='g'></div>\n";
    doCheckbox('rev_notifychair', 'Notify PC chairs of newly submitted reviews by email');


    // Deadlines
    echo "<h3 id=\"rounds\" class=\"settings g\">Deadlines &amp; rounds</h3>\n";
    $date_text = $DateExplanation;
    $DateExplanation = "";
    echo '<p class="hint">Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.<br />', $date_text, '</p>';

    $rounds = $Conf->round_list();
    if ($sv->has_errors()) {
        for ($i = 1; isset($_POST["roundname_$i"]); ++$i)
            $rounds[$i] = get($_POST, "deleteround_$i") ? ";" : trim(get_s($_POST, "roundname_$i"));
    }

    // prepare round selector
    $round_value = trim(xsetting_data("rev_roundtag"));
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
        && (!$sv->has_errors() || isset($_POST["roundname_0"]))
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
        setting_label("rev_roundtag", "Current round"),
        '</td><td>',
        Ht::select("rev_roundtag", $selector, $round_value, setting_js("rev_roundtag")),
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
    doCheckbox("extrev_chairreq", "PC chair must approve proposed external reviewers");
    doCheckbox("pcrev_editdelegate", "PC members can edit external reviews they requested");

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
    if ($sv->has_errors()) {
        $tclass = defval($_POST, "${type}_track$tnum", "");
        $ttag = defval($_POST, "${type}tag_track$tnum", "");
    } else if ($thistrack && get($thistrack, $type)) {
        if ($thistrack->$type == "+none")
            $tclass = "none";
        else {
            $tclass = substr($thistrack->$type, 0, 1);
            $ttag = substr($thistrack->$type, 1);
        }
    }

    echo "<tr data-fold=\"true\" class=\"fold", ($tclass == "" || $tclass == "none" ? "c" : "o"), "\">",
        "<td class=\"lxcaption\">",
        setting_label(array("{$type}_track$tnum", "{$type}tag_track$tnum"),
                      $question, "{$type}_track$tnum"),
        "</td>",
        "<td>",
        Ht::select("{$type}_track$tnum",
                   array("" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"),
                   $tclass,
                   setting_js("{$type}_track$tnum", array("onchange" => "void foldup(this,event,{f:this.selectedIndex==0||this.selectedIndex==3})"))),
        " &nbsp;",
        Ht::entry("${type}tag_track$tnum", $ttag,
                  setting_js("{$type}tag_track$tnum", array("class" => "fx", "placeholder" => "(tag)"))),
        "</td></tr>";
}

function do_track($sv, $trackname, $tnum) {
    global $Conf;
    echo "<div id=\"trackgroup$tnum\"",
        ($tnum ? "" : " style=\"display:none\""),
        "><div class=\"trackname\" style=\"margin-bottom:3px\">";
    if ($trackname === "_")
        echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
    else
        echo setting_label("name_track$tnum", "For papers with tag &nbsp;"),
            Ht::entry("name_track$tnum", $trackname, setting_js("name_track$tnum", array("placeholder" => "(tag)"))), ":";
    echo "</div>\n";

    $t = $Conf->setting_json("tracks");
    $t = $t && $trackname !== "" ? get($t, $trackname) : null;
    echo "<table style=\"margin-left:1.5em;margin-bottom:0.5em\">";
    do_track_permission($sv, "view", "Who can view these papers?", $tnum, $t);
    do_track_permission($sv, "viewpdf", "Who can view PDFs?<br><span class=\"hint\">Assigned reviewers can always view PDFs.</span>", $tnum, $t);
    do_track_permission($sv, "viewrev", "Who can view reviews?", $tnum, $t);
    do_track_permission($sv, "assrev", "Who can be assigned a review?", $tnum, $t);
    do_track_permission($sv, "unassrev", "Who can self-assign a review?", $tnum, $t);
    if ($trackname === "_")
        do_track_permission($sv, "viewtracker", "Who can view the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", $tnum, $t);
    echo "</table></div>\n\n";
}

function doTagsGroup($sv) {
    global $Conf, $DateExplanation;

    // Tags
    $tagger = new Tagger;
    echo "<h3 class=\"settings\">Tags</h3>\n";

    echo "<table><tr><td class='lxcaption'>", setting_label("tag_chair", "Chair-only tags"), "</td>";
    if ($sv->has_errors())
        $v = defval($_POST, "tag_chair", "");
    else
        $v = join(" ", array_keys(TagInfo::chair_tags()));
    echo "<td>", Ht::hidden("has_tag_chair", 1);
    doEntry("tag_chair", $v, 40);
    echo "<br /><div class='hint'>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</div></td></tr>";

    echo "<tr><td class='lxcaption'>", setting_label("tag_approval", "Approval voting tags"), "</td>";
    if ($sv->has_errors())
        $v = defval($_POST, "tag_approval", "");
    else {
        $x = "";
        foreach (TagInfo::approval_tags() as $n => $v)
            $x .= "$n ";
        $v = trim($x);
    }
    echo "<td>", Ht::hidden("has_tag_approval", 1);
    doEntry("tag_approval", $v, 40);
    echo "<br /><div class='hint'><a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lxcaption'>", setting_label("tag_vote", "Allotment voting tags"), "</td>";
    if ($sv->has_errors())
        $v = defval($_POST, "tag_vote", "");
    else {
        $x = "";
        foreach (TagInfo::vote_tags() as $n => $v)
            $x .= "$n#$v ";
        $v = trim($x);
    }
    echo "<td>", Ht::hidden("has_tag_vote", 1);
    doEntry("tag_vote", $v, 40);
    echo "<br /><div class='hint'>“vote#10” declares an allotment of 10 votes per PC member. <span class='barsep'>·</span> <a href='", hoturl("help", "t=votetags"), "'>What is this?</a></div></td></tr>";

    echo "<tr><td class='lxcaption'>", setting_label("tag_rank", "Ranking tag"), "</td>";
    if ($sv->has_errors())
        $v = defval($_POST, "tag_rank", "");
    else
        $v = $Conf->setting_data("tag_rank", "");
    echo "<td>", Ht::hidden("has_tag_rank", 1);
    doEntry("tag_rank", $v, 40);
    echo "<br /><div class='hint'>The <a href='", hoturl("offline"), "'>offline reviewing page</a> will expose support for uploading rankings by this tag. <span class='barsep'>·</span> <a href='", hoturl("help", "t=ranking"), "'>What is this?</a></div></td></tr>";
    echo "</table>";

    echo "<div class='g'></div>\n";
    doCheckbox('tag_seeall', "PC can see tags for conflicted papers");

    preg_match_all('_(\S+)=(\S+)_', $Conf->setting_data("tag_color", ""), $m,
                   PREG_SET_ORDER);
    $tag_colors = array();
    foreach ($m as $x)
        $tag_colors[TagInfo::canonical_color($x[2])][] = $x[1];
    $tag_colors_rows = array();
    foreach (explode("|", TagInfo::BASIC_COLORS) as $k) {
        if ($sv->has_errors())
            $v = defval($_POST, "tag_color_$k", "");
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
        if ($sv->has_errors())
            $v = defval($_POST, "tag_badge_$k", "");
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
    if ($sv->has_errors())
        for ($i = 1; isset($_POST["name_track$i"]); ++$i) {
            $trackname = trim($_POST["name_track$i"]);
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
    if (value("au_seerev") == Conf::AUSEEREV_UNLESSINCOMPLETE
        && !get($Opt, "allow_auseerev_unlessincomplete"))
        $Conf->save_setting("opt.allow_auseerev_unlessincomplete", 1);
    if (get($Opt, "allow_auseerev_unlessincomplete"))
        $opts[Conf::AUSEEREV_UNLESSINCOMPLETE] = "Yes, after completing any assigned reviews for other papers";
    $opts[Conf::AUSEEREV_TAGS] = "Yes, for papers with any of these tags:&nbsp; " . render_entry("tag_au_seerev", xsetting_data("tag_au_seerev"), 24);
    doRadio("au_seerev", $opts);
    echo Ht::hidden("has_tag_au_seerev", 1);

    // Authors' response
    echo '<div class="g"></div><table id="foldauresp" class="fold2o">';
    doCheckbox('resp_active', "<b>Collect authors’ responses to the reviews<span class='fx2'>:</span></b>", true, "void fold('auresp',!this.checked,2)");
    echo '<tr class="fx2"><td></td><td><div id="auresparea">',
        Ht::hidden("has_resp_rounds", 1);

    // Response rounds
    if ($sv->has_errors()) {
        $rrounds = array(1);
        for ($i = 1; isset($_POST["resp_roundname_$i"]); ++$i)
            $rrounds[$i] = $_POST["resp_roundname_$i"];
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
        if (xsetting("resp_open$isuf") === 1 && ($x = xsetting("resp_done$isuf")))
            $Conf->settings["resp_open$isuf"] = $x - 7 * 86400;
        doDateRow("resp_open$isuf", "Start time", null, "lxcaption");
        doDateRow("resp_done$isuf", "Hard deadline", null, "lxcaption");
        doGraceRow("resp_grace$isuf", "Grace period", "lxcaption");
        doTextRow("resp_words$isuf", array("Word limit", $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit."),
                  xsetting("resp_words$isuf", 500), 5, "lxcaption", "none");
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
            if ($sv->has_errors())
                $v = defval($_POST, "dec$k", $v);
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
    if ($sv->has_errors()) {
        $v = defval($_POST, "decn", $v);
        $vclass = defval($_POST, "dtypn", $vclass);
    }
    echo '<tr><td class="lcaption">',
        setting_label("decn", "New decision type"),
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
    doCheckbox('final_open', '<b>Collect final versions of accepted papers<span class="fx">:</span></b>', true, "void fold('final',!this.checked,2)");
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
