<?php
// settingvalues.php -- HotCRP conference settings management helper classes
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class SettingParser {
    /** @return bool */
    function set_oldv(SettingValues $sv, Si $si) {
        return false;
    }

    /** @return void */
    function parse_req(SettingValues $sv, Si $si) {
    }

    /** @return mixed */
    function unparse_json(SettingValues $sv, Si $si) {
        error_log("Si {$si->name} missing");
        return null;
    }

    /** @return void */
    function store_value(SettingValues $sv, Si $si) {
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

    /** @var associative-array<string,SettingParser> */
    private $parsers = [];
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

    /** @var array<string,null|int|float|string> */
    public $req = [];
    public $req_files = [];
    /** @var bool */
    private $_req_parsed = false;
    /** @var array<string,array{?int,?string}> */
    public $savedv = [];
    /** @var array<string,null|int|string> */
    private $explicit_oldv = [];
    /** @var array<string,true> */
    private $hint_status = [];
    /** @var ?Mailer */
    private $null_mailer;

    /** @var ?GroupedExtensions */
    private $_gxt;

    function __construct(Contact $user) {
        parent::__construct();
        $this->conf = $user->conf;
        $this->user = $user;
        $this->all_perm = $user->privChair;
        foreach (Tagger::split_unpack($user->contactTags ?? "") as $ti) {
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
        if (str_starts_with($k, "has_")) {
            $k = substr($k, 4);
            $this->req[$k] = $this->req[$k] ?? null;
        }
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
    /** @param null|string|Si $field
     * @param MessageItem $mi */
    function append_item_at($field, $mi) {
        if (is_array($field)) {
            error_log("unexpected add_at with array " . json_encode($field) . " " . debug_string_backtrace());
            foreach ($field as $f) {
                $this->append_item_at($f, $mi);
            }
        } else {
            $fname = $field instanceof Si ? $field->name : $field;
            if ($mi->field !== null && $mi->field !== $fname) {
                $mi = clone $mi;
            }
            $mi->field = $fname;
            parent::append_item($mi);
        }
        return $mi;
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @return MessageItem */
    function error_at($field, $msg = null) {
        $msg = $msg === null || $msg === false ? "" : $msg;
        return $this->append_item_at($field, new MessageItem(null, $msg, MessageSet::ERROR));
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @return MessageItem */
    function warning_at($field, $msg = null) {
        $msg = $msg === null || $msg === false ? "" : $msg;
        return $this->append_item_at($field, new MessageItem(null, $msg, MessageSet::WARNING));
    }

    /** @param MessageItem $mi
     * @param list<string> $loc
     * @return MessageItem */
    static private function decorate_message_item($mi, $loc) {
        if ($loc) {
            $mi->message = "<5>" . join(", ", $loc) . ": " . $mi->message_as(5);
        }
        return $mi;
    }

    /** @return \Generator<MessageItem> */
    private function decorated_message_list() {
        $lastmi = null;
        $lastloc = [];
        foreach ($this->message_list() as $mi) {
            $mi = clone $mi;
            if ($mi->status === MessageSet::WARNING) {
                $mi->message = "<5>Warning: " . $mi->message_as(5);
            }
            $loc = null;
            if ($mi->field
                && ($si = $this->conf->si($mi->field))) {
                $loc = $si->title_html($this);
                if ($loc && $si->hashid !== false) {
                    $loc = Ht::link($loc, $si->sv_hoturl($this));
                }
            }
            if ($lastmi && $lastmi->message !== $mi->message) {
                yield self::decorate_message_item($lastmi, $lastloc);
                $lastmi = null;
            }
            if (!$lastmi) {
                $lastmi = $mi;
                $lastloc = [];
            }
            if ($loc) {
                $lastloc[] = $loc;
            }
        }
        if ($lastmi) {
            yield self::decorate_message_item($lastmi, $lastloc);
        }
    }

    /** @param bool $is_update */
    function report($is_update = false) {
        $msgs = [];
        if ($is_update && $this->has_error()) {
            $msgs[] = new MessageItem("", "Your changes were not saved. Please fix these errors and try again.", MessageSet::PLAIN);
        }
        foreach ($this->decorated_message_list() as $mi) {
            $msgs[] = $mi;
        }
        if (!empty($msgs)) {
            if ($this->has_error()) {
                Conf::msg_error(MessageSet::feedback_html($msgs), true);
            } else {
                Conf::msg_warning(MessageSet::feedback_html($msgs), true);
            }
        }
    }

    /** @return SettingParser */
    private function si_parser(Si $si) {
        $class = $si->parser_class;
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
            return "{$c1} {$c2}";
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
     * @return array<string,mixed> */
    function sjs($name, $js = null) {
        if ($name instanceof Si) {
            $si = $name;
            $name = $si->name;
        } else {
            $si = $this->conf->si($name);
        }
        $x = ["id" => $name];
        if ($si && !isset($js["disabled"]) && !isset($js["readonly"])) {
            if ($si->disabled) {
                $x["disabled"] = true;
            } else if (!$this->si_editable($si)) {
                if (in_array($si->type, ["checkbox", "radio", "select", "cdate", "tagselect"], true)) {
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
                $x["data-default-value"] = $si->base_unparse_reqv($this->si_oldv($si));
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
        if (($si = $this->conf->si($name))) {
            return $si;
        } else {
            throw new Exception(caller_landmark(2) . ": Unknown setting “{$name}”");
        }
    }

    /** @param string $page */
    function set_canonical_page($page) {
        $this->canonical_page = $page;
    }

    /** @param string $name
     * @return bool */
    function editable($name) {
        return $this->si_editable($this->si($name));
    }

    /** @return bool */
    function si_editable(Si $si) {
        assert(!!$si->group);
        $perm = $this->all_perm;
        if ($this->perm !== null) {
            for ($i = 0; $i !== count($this->perm); $i += 2) {
                if ($si->group === $this->perm[$i]
                    || ($si->tags !== null && in_array($this->perm[$i], $si->tags, true))) {
                    if ($this->perm[$i + 1]) {
                        $perm = true;
                    } else {
                        return false;
                    }
                }
            }
        }
        return $perm;
    }

    /** @param string $name
     * @return null|int|string */
    function oldv($name) {
        return $this->si_oldv($this->si($name));
    }

    /** @param string $name
     * @param null|int|string $value */
    function set_oldv($name, $value) {
        $this->explicit_oldv[$name] = $value;
    }

    /** @return null|int|string */
    function si_oldv(Si $si) {
        if (array_key_exists($si->name, $this->explicit_oldv)
            || ($si->parser_class && $this->si_parser($si)->set_oldv($this, $si))) {
            $val = $this->explicit_oldv[$si->name];
        } else if ($si->storage_type & Si::SI_OPT) {
            $val = $this->conf->opt(substr($si->storage_name(), 4)) ?? $si->default_value;
            if (($si->storage_type & Si::SI_VALUE) && is_bool($val)) {
                $val = (int) $val;
            }
        } else if ($si->storage_type & Si::SI_DATA) {
            $val = $this->conf->setting_data($si->storage_name()) ?? $si->default_value;
        } else if ($si->storage_type & Si::SI_VALUE) {
            $val = $this->conf->setting($si->storage_name()) ?? $si->default_value;
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
        return array_key_exists($si->storage_name(), $this->savedv);
    }

    /** @param string $name */
    function savedv($name) {
        $si = $this->si($name);
        assert($si->storage_type !== Si::SI_NONE);
        return $this->si_savedv($si->storage_name(), $si);
    }

    private function si_savedv($s, Si $si) {
        if (array_key_exists($s, $this->savedv)) {
            $v = $this->savedv[$s];
            if ($v !== null) {
                $vx = $v[$si->storage_type & Si::SI_DATA ? 1 : 0];
                if ($si->storage_type & Si::SI_NEGATE) {
                    $vx = $vx ? 0 : 1;
                }
                return $vx;
            } else {
                return $si->default_value;
            }
        } else {
            return null;
        }
    }

    /** @param string $name */
    function newv($name) {
        $si = $this->si($name);
        $s = $si->storage_name();
        if (array_key_exists($s, $this->savedv)) {
            return $this->si_savedv($s, $si);
        } else {
            return $this->si_oldv($si);
        }
    }

    /** @param string $name
     * @return bool */
    function has_interest($name) {
        return !$this->canonical_page
            || $this->si_has_interest($this->si($name));
    }

    /** @return bool */
    function si_has_interest(Si $si) {
        return !$this->canonical_page
            || !$si->group
            || $si->group === $this->canonical_page
            || (isset($si->tags) && in_array($this->canonical_page, $si->tags))
            || array_key_exists($si->storage_name(), $this->savedv);
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
        $s = $si->storage_name();
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
     * @return string */
    function feedback_at($field) {
        $fname = $field instanceof Si ? $field->name : $field;
        return $this->feedback_html_at($fname);
    }

    /** @param string $field */
    function echo_feedback_at($field) {
        echo $this->feedback_at($field);
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
            Ht::checkbox($name, 1, $x !== null && $x > 0, $this->sjs($name, $js));
    }

    /** @param string $name
     * @param string $text
     * @param ?array<string,mixed> $js
     * @param string $hint
     * @return void */
    function echo_checkbox($name, $text, $js = null, $hint = "") {
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
                Ht::radio($name, $k, $k == $x, $this->sjs($name, $item)),
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
            $v = $si->base_unparse_reqv($v);
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
        return Ht::entry($name, $v, $this->sjs($si, $js)) . $t;
    }

    /** @param string $name
     * @return void */
    function echo_entry($name) {
        echo $this->entry($name);
    }

    /** @param string $name
     * @param string $description
     * @param string $control
     * @param ?array<string,mixed> $js
     * @param string $hint */
    function echo_control_group($name, $description, $control,
                                $js = null, $hint = "") {
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
     * @param string $hint
     * @return void */
    function echo_entry_group($name, $description, $js = null, $hint = "") {
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
        return Ht::select($name, $values, $v !== null ? $v : 0, $this->sjs($si, $js)) . $t;
    }

    /** @param string $name
     * @param array $values
     * @param string $description
     * @param string $hint */
    function echo_select_group($name, $values, $description, $js = null, $hint = "") {
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
        return Ht::textarea($name, $v, $this->sjs($si, $js)) . $t;
    }

    /** @param string $name
     * @param string $description
     * @param string $hint
     * @param string $xclass */
    private function echo_message_base($name, $description, $hint, $xclass) {
        $si = $this->si($name);
        if (str_starts_with($si->storage_name(), "msg.")) {
            $si->default_value = $this->si_message_default($si);
        }
        $current = $this->curv($name);
        $description = '<a class="ui q js-foldup" href="">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="f-i has-fold fold', ($current == $si->default_value ? "c" : "o"), '">',
            '<div class="f-c', $xclass, ' ui js-foldup">',
            $this->label($name, $description),
            ' <span class="n fx">(HTML allowed)</span></div>',
            $this->feedback_at($name),
            $this->textarea($name, ["class" => "fx"]),
            $hint, "</div>\n";
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function echo_message($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, "");
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function echo_message_minor($name, $description, $hint = "") {
        $this->echo_message_base($name, $description, $hint, " n");
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function echo_message_horizontal($name, $description, $hint = "") {
        $si = $this->si($name);
        if (str_starts_with($si->storage_name(), "msg.")) {
            $si->default_value = $this->si_message_default($si);
        }
        $current = $this->curv($name);
        if ($current !== $si->default_value) {
            echo '<div class="entryi">',
                $this->label($name, $description), '<div>';
            $close = "";
        } else {
            $description = '<a class="ui q js-foldup href="">'
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

    /** @param string $name0
     * @param string $name1
     * @param bool $force_name0
     * @return bool */
    function check_date_before($name0, $name1, $force_name0) {
        if (($d1 = $this->newv($name1))) {
            $d0 = $this->newv($name0);
            if (!$d0) {
                if ($force_name0) {
                    $this->save($name0, $d1);
                }
            } else if ($d0 > $d1) {
                $si1 = $this->si($name1);
                $this->error_at($this->si($name0), "Must come before " . $this->setting_link($si1->title_html($this), $si1) . ".");
                $this->error_at($si1);
                return false;
            }
        }
        return true;
    }

    /** @param string $type
     * @return string */
    function type_hint($type) {
        if (str_ends_with($type, "date") && !isset($this->hint_status["date"])) {
            $this->hint_status["date"] = true;
            return "Date examples: “now”, “10 Dec 2006 11:59:59pm PST”, “2019-10-31 UTC-1100”, “Dec 31 AoE” <a href=\"http://php.net/manual/en/datetime.formats.php\">(more examples)</a>";
        } else if ($type === "grace" && !isset($this->hint_status["grace"])) {
            $this->hint_status["grace"] = true;
            return "Example: “15 min”";
        } else {
            return "";
        }
    }

    /** @param string $name
     * @param bool $use_default
     * @return array{subject:string,body:string} */
    function expand_mail_template($name, $use_default) {
        if (!$this->null_mailer) {
            $this->null_mailer = new HotCRPMailer($this->conf, null, ["width" => false]);
        }
        return $this->null_mailer->expand_template($name, $use_default);
    }

    /** @param Si $si
     * @return string */
    function si_message_default($si) {
        if ($si->default_message === null) {
            assert(str_starts_with($si->storage_name(), "msg."));
            $args = [substr($si->storage_name(), 4), ""];
        } else if (is_string($si->default_message)) {
            $args = [$si->default_message];
        } else {
            assert(is_array($si->default_message));
            $args = $si->default_message;
            array_splice($args, 1, 0, "");
        }
        $mid = $si->split_name ? $si->split_name[1] : "";
        for ($i = 2; $i < count($args); ++$i) {
            $args[$i] = $this->newv(str_replace("\$", $mid, $args[$i]));
        }
        return $this->conf->ims()->default_itext(...$args);
    }

    /** @param string|Si $si
     * @return string */
    function setting_link($html, $si, $js = null) {
        $si = is_string($si) ? $this->si($si) : $si;
        return Ht::link($html, $si->sv_hoturl($this), $js);
    }


    /** @param string|Si $si
     * @return null|int|string */
    function base_parse_req($si) {
        $si = is_string($si) ? $this->si($si) : $si;
        $v = $si->base_parse_reqv($this, $this->reqv($si->name));
        if ($v === false) {
            $this->error_at($si, $si->last_parse_error);
            return null;
        } else {
            return $v;
        }
    }

    private function execute_req(Si $si) {
        if ($si->internal
            || $si->disabled
            || !$this->si_editable($si)) {
            /* ignore changes to disabled/internal settings */
        } else if ($si->parser_class) {
            $this->si_parser($si)->parse_req($this, $si);
        } else if ($si->storage_type !== Si::SI_NONE
                   && ($v = $this->base_parse_req($si)) !== null) {
            if (is_int($v)
                && $v <= 0
                && $si->type !== "radio"
                && $si->type !== "zint") {
                $v = null;
            }
            $this->save($si->name, $v);
            if ($si->ifnonempty) {
                $this->save($si->ifnonempty, $v === null || $v === "" ? null : 1);
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

    /** @param Si $si */
    function request_store_value($si) {
        $this->saved_si[] = $si;
    }

    /** @return $this */
    function parse() {
        assert(!$this->_req_parsed);

        // find requested settings
        $siset = $this->conf->si_set();
        $reqsi = [];
        foreach ($this->req as $k => $v) {
            if (str_starts_with($k, "has_")) {
                $si = $siset->get(substr($k, 4));
            } else {
                $si = $siset->get($k);
            }
            if ($si && $this->req_has_si($si)) {
                $reqsi[$si->name] = $si;
            }
        }
        uasort($reqsi, "Conf::xt_order_compare");

        // parse and validate settings
        foreach ($reqsi as $si) {
            $this->execute_req($si);
        }

        $this->_req_parsed = true;
        return $this;
    }

    /** @return bool */
    function execute() {
        $this->_req_parsed || $this->parse();

        // obtain locks
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
                $this->si_parser($si)->store_value($this, $si);
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
                $this->conf->qe("insert into Settings (name, value, data) values ?v ?U on duplicate key update value=?U(value), data=?U(data)", $av);
                //Conf::msg_info(Ht::pre_text_wrap(Dbl::format_query("insert into Settings (name, value, data) values ?v ?U on duplicate key update value=?U(value), data=?U(data)", $av)));
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
                   || $si->type === "tagselect"
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
