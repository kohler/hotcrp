<?php
// settingvalues.php -- HotCRP conference settings manager
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SettingValues extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var ?string */
    public $canonical_page;
    /** @var bool */
    public $all_interest = false;
    /** @var bool */
    public $link_json = false;
    /** @var list<string|bool> */
    private $perm;
    /** @var bool */
    private $all_perm;

    /** @var array<string,?string> */
    public $req = [];
    /** @var array<string,QrequestFile> */
    public $req_files = [];
    /** @var bool */
    private $_use_req = true;
    /** @var 0|1|2 */
    private $_req_parse_state = 0;
    /** @var bool */
    private $_req_sorted = false;
    /** @var ?list<string> */
    private $_new_req;

    /** @var array<string,true> */
    private $_hint_status = [];
    /** @var ?Mailer */
    private $_null_mailer;
    /** @var ?Tagger */
    private $_tagger;

    /** @var array<string,mixed> */
    private $_explicit_oldv = [];
    /** @var array<string,true> */
    private $_oblist_ensured = [];
    /** @var array<string,int> */
    private $_oblist_next = [];
    /** @var array<string,array<int,int>> */
    private $_oblist_ctrmap = [];
    /** @var array<string,object> */
    private $_object_parsingv = [];
    /** @var Collator */
    private $_icollator;

    /** @var array<string,array{?int,?string}> */
    private $_savedv = [];
    /** @var array<string,mixed> */
    private $_explicit_newv = [];

    /** @var list<Si> */
    private $_saved_si = [];
    /** @var list<array{?string,callable()}> */
    private $_cleanup_callbacks = [];
    /** @var array<string,int> */
    private $_table_lock = [];
    /** @var associative-array<string,true> */
    private $_diffs = [];
    /** @var associative-array<string,false> */
    private $_no_diffs = [];
    /** @var associative-array<string,true> */
    private $_invalidate_caches = [];

    /** @var ?ComponentSet */
    private $_cs;
    /** @var ?string */
    private $_jpath;
    /** @var ?JsonParser */
    private $_jp;
    /** @var bool */
    private $_inputs_printed = false;

    function __construct(Contact $user) {
        parent::__construct();
        $this->set_want_ftext(true, 5);
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
        $this->_icollator = new Collator("en_US.utf8");
        $this->_icollator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $this->_icollator->setAttribute(Collator::STRENGTH, Collator::SECONDARY);
    }

    /** @param Qrequest|array<string,string|int|float> $qreq */
    static function make_request(Contact $user, $qreq) {
        return (new SettingValues($user))->add_request($qreq);
    }

    /** @param string $page
     * @return $this */
    function set_canonical_page($page) {
        $this->canonical_page = $page;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_all_interest($x) {
        $this->all_interest = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_link_json($x) {
        $this->link_json = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_use_req($x) {
        assert($this->_use_req === $x || empty($this->_oblist_ensured));
        $this->_use_req = $x;
        return $this;
    }

    /** @param string $k
     * @param string $v
     * @return $this */
    function set_req($k, $v) {
        if (str_starts_with($k, "has_")) {
            $k = substr($k, 4);
            if (($x = array_key_exists($k, $this->req))) {
                return $this;
            }
            $v = null;
        } else {
            $x = array_key_exists($k, $this->req);
        }
        $this->req[$k] = $v;
        if (!$x) {
            $this->_req_sorted = false;
            if ($this->_new_req !== null) {
                $this->_new_req[] = $k;
            }
        }
        return $this;
    }

    /** @param string $k
     * @return $this */
    function unset_req($k) {
        if (array_key_exists($k, $this->req)) {
            $this->_req_sorted = false;
            unset($this->req[$k]);
        }
        return $this;
    }

    /** @param Qrequest|array<string,string|int|float> $qreq
     * @return $this */
    function add_request($qreq) {
        assert(empty($this->_oblist_ensured));
        foreach ($qreq as $k => $v) {
            $this->set_req($k, (string) $v);
        }
        if ($qreq instanceof Qrequest) {
            foreach ($qreq->files() as $f => $finfo) {
                $this->req_files[$f] = $finfo;
            }
        }
        foreach ($this->conf->si_set()->aliases() as $in => $out) {
            if (array_key_exists($in, $this->req)
                && !array_key_exists($out, $this->req))
                $this->req[$out] = $this->req[$in];
        }
        return $this;
    }

    /** @param string $jstr
     * @param ?string $filename
     * @return $this */
    function add_json_string($jstr, $filename = null) {
        assert($this->_use_req === true);
        assert(empty($this->_oblist_ensured));
        $this->_jp = (new JsonParser($jstr))->flags(JsonParser::JSON5)->filename($filename);
        $j = $this->_jp->decode();
        if ($j !== null || $this->_jp->error_type === 0) {
            $this->_jpath = "";
            $this->set_json_parts("", $j);
        } else {
            $mi = $this->error_at(null, "<0>Invalid JSON: " . $this->_jp->last_error_msg());
            $mi->pos1 = $mi->pos2 = $this->_jp->error_pos;
        }
        $this->_jpath = null;
        return $this;
    }

    /** @param string $parts
     * @param mixed $j */
    private function set_json_parts($parts, $j) {
        if (!is_object($j)) {
            $this->error_at(null, "<0>Expected JSON object");
            return;
        }
        $si_set = $this->conf->si_set();
        $jpath = $this->_jpath;
        foreach ((array) $j as $k => $v) {
            $si = $si_set->get("{$parts}{$k}");
            $this->_jpath = JsonParser::path_push($jpath, $k);
            $name = $si ? $si->name : "{$parts}{$k}";
            if (!$si) {
                if ($k === "delete" && $parts !== "") {
                    if ($v === true) {
                        $this->set_req($name, "1");
                    } else if ($v === false) {
                        $this->unset_req($name);
                    } else {
                        $this->error_at(null, "<0>Boolean required");
                    }
                } else if ($k === "reset" || str_ends_with($k, "_reset")) {
                    if (is_bool($v)) {
                        $this->set_req($name, $v ? "1" : "");
                    } else {
                        $this->error_at(null, "<0>Boolean required");
                    }
                } else if ($k !== "" && $k[0] !== "\$" && $k[0] !== "#") {
                    $mi = $this->warning_at($name, "<0>Unknown setting");
                    if (($jpp = $this->_jp->path_position($this->_jpath))) {
                        $mi->pos1 = $jpp->kpos1;
                        $mi->pos2 = $jpp->kpos2;
                    }
                }
            } else if (!$si->json_import()) {
                $this->warning_at(null, "<0>This setting cannot be changed in JSON");
            } else if ($si->internal && is_scalar($v)) {
                $this->set_req($si->name, "{$v}");
            } else if ($si->type === "oblist") {
                if (is_array($v)) {
                    $this->set_req("has_{$si->name}", "1");
                    $myjpath = $this->_jpath;
                    foreach ($v as $i => $vv) {
                        $this->_jpath = "{$myjpath}[{$i}]";
                        $pfx = "{$si->name}/" . ($i + 1) . "/";
                        if (!array_key_exists("{$pfx}id", $this->req)) {
                            $this->req["{$pfx}id"] = "";
                        }
                        if (is_string($vv) && $si->subtype === "allow_bare_name") {
                            $vv = (object) ["name" => $vv];
                        }
                        if (is_object($vv)) {
                            $this->set_json_parts($pfx, $vv);
                        } else {
                            $this->error_at(null, "<0>Expected JSON object");
                        }
                    }
                    $this->_jpath = $myjpath;
                } else {
                    $this->error_at(null, "<0>Expected array of JSON objects");
                }
            } else if ($si->type === "object") {
                if (is_object($v)) {
                    $this->set_req("has_{$si->name}", "1");
                    $this->set_json_parts("{$si->name}/", $v);
                } else {
                    $this->error_at(null, "<0>Expected JSON object");
                }
            } else if (($vstr = $si->jsonv_reqstr($v, $this)) !== null) {
                $this->set_req($si->name, $vstr);
            }
        }
    }

    function session_highlight(Qrequest $qreq) {
        if (($sh = $qreq->csession("settings_highlight"))) {
            foreach ($sh as $f => $v) {
                $this->msg_at($f, null, $v);
            }
            $qreq->unset_csession("settings_highlight");
        }
    }

    /** @return bool */
    function viewable_by_user() {
        for ($i = 0; $i !== count($this->perm ?? []); $i += 2) {
            if ($this->perm[$i + 1])
                return true;
        }
        return $this->all_perm;
    }


    /** @return ComponentSet */
    function cs() {
        if ($this->_cs === null) {
            $this->_cs = new ComponentSet($this->user, ["etc/settinggroups.json"], $this->conf->opt("settingGroups"));
            $this->_cs->set_title_class("form-h")
                ->set_section_class("form-section")
                ->set_separator('<hr class="form-sep">')
                ->set_context_args($this)
                ->add_print_callback([$this, "_print_callback"]);
        }
        return $this->_cs;
    }

    /** @param string $g
     * @return ?string */
    function canonical_group($g) {
        return $this->cs()->canonical_group(strtolower($g));
    }

    /** @param string $g
     * @return ?string */
    function group_title($g) {
        $gj = $this->cs()->get($g);
        return $gj && $gj->name === $gj->group ? $gj->title : null;
    }

    /** @param string $g
     * @return ?string */
    function group_hashid($g) {
        $gj = $this->cs()->get($g);
        return $gj && isset($gj->hashid) ? $gj->hashid : null;
    }

    /** @param string $g
     * @return list<object> */
    function group_members($g) {
        return $this->cs()->members(strtolower($g));
    }

    /** @param string $g
     * @return ?object */
    function group_item($g) {
        return $this->cs()->get($g);
    }

    function crosscheck() {
        foreach ($this->cs()->members("__crosscheck", "crosscheck_function") as $gj) {
            $this->cs()->call_function($gj, $gj->crosscheck_function, $gj);
        }
    }

    /** @param string $name */
    function print($name) {
        return $this->cs()->print($name);
    }

    /** @param string $g */
    function print_members($g) {
        $this->cs()->print_members($g);
    }

    /** @param string $title
     * @param ?string $hashid */
    function print_start_section($title, $hashid = null) {
        $this->cs()->print_start_section($title, $hashid);
    }

    /** @param object $gj
     * @return ?bool */
    function _print_callback($gj) {
        $inputs = $gj->inputs ?? null;
        if ($inputs || (isset($gj->print_function) && $inputs === null)) {
            $this->_inputs_printed = true;
        }
        return null;
    }


    /** @param MessageItem $mi
     * @param ?string $field
     * @return MessageItem */
    private function with_jfield($mi, $field) {
        $updates = [];
        $jpp = null;
        if ($field !== null) {
            $updates["field"] = $field;
            $path = "\$";
            foreach (explode("/", $field) as $part) {
                $path = JsonParser::path_push($path, ctype_digit($part) ? intval($part) - 1 : $part);
            }
            $jpp = $this->_jp->path_position($path);
        } else if ($this->_jpath !== "") {
            $field = "";
            foreach (JsonParser::path_split($this->_jpath) as $i => $part) {
                $field .= ($i === 0 ? "" : "/") . (is_int($part) ? $part + 1 : $part);
            }
            $updates["field"] = $field;
            $jpp = $this->_jp->path_position($this->_jpath);
        }
        if ($jpp) {
            $updates["pos1"] = $jpp->vpos1;
            $updates["pos2"] = $jpp->vpos2;
            $updates["context"] = null; // reset existing context
        }
        if (isset($updates["pos1"])
            && $this->_jp->has_filename()
            && ($lm = $this->_jp->position_landmark($jpp->vpos1))) {
            $updates["landmark"] = $lm;
        }
        return $mi->with($updates);
    }

    /** @param null|string|Si $field
     * @param MessageItem $mi
     * @return MessageItem */
    function append_item_at($field, $mi) {
        $fname = $field instanceof Si ? $field->name : $field;
        if ($this->_jp !== null) {
            $mi = $this->with_jfield($mi, $fname);
        } else {
            $mi = $mi->with_field($fname);
        }
        return $this->append_item($mi);
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        $fname = $field instanceof Si ? $field->name : $field;
        if ($this->_jp !== null) {
            $mi = $this->with_jfield(new MessageItem(null, $msg ?? "", $status), $fname);
        } else {
            $mi = new MessageItem($fname, $msg ?? "", $status);
        }
        return $this->append_item($mi);
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @return MessageItem */
    function error_at($field, $msg = null) {
        return $this->msg_at($field, $msg, MessageSet::ERROR);
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @return MessageItem */
    function warning_at($field, $msg = null) {
        return $this->msg_at($field, $msg, MessageSet::WARNING);
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @return MessageItem */
    function inform_at($field, $msg = null) {
        return $this->msg_at($field, $msg, MessageSet::INFORM);
    }

    /** @param MessageItem $mi
     * @param list<string> $loc
     * @param ?MessageItem $prevmi
     * @return MessageItem */
    static private function decorate_message_item($mi, $loc, $prevmi) {
        if ($loc
            && ($mi->status !== MessageSet::INFORM || !$prevmi)) {
            $mi->message = "<5>" . join(", ", $loc) . ": " . $mi->message_as(5);
        }
        return $mi;
    }

    /** @return \Generator<MessageItem> */
    private function decorated_message_list() {
        $lastmi = $prevmi = null;
        $lastloc = [];
        foreach ($this->message_list() as $mi) {
            $mi = clone $mi;
            if ($mi->status === MessageSet::WARNING) {
                $mi->message = "<5>Warning: " . $mi->message_as(5);
            }
            $loc = null;
            if ($mi->field) {
                $si = $this->conf->si($mi->field);
                $loc = $si ? $si->title_html($this) : "";
                if ($this->link_json) {
                    $jpath = ($si ? $si->json_path() : null) ?? Si::json_path_for($mi->field);
                    $loc = $this->json_path_link($loc, $jpath);
                } else if ($loc !== "" && $si->has_hashid()) {
                    $loc = $this->setting_link($loc, $si);
                }
            }
            if ($lastmi
                && ($lastmi->message !== $mi->message || $lastmi->pos1 !== null)) {
                yield self::decorate_message_item($lastmi, $lastloc, $prevmi);
                $prevmi = $lastmi;
                $lastmi = null;
                $lastloc = [];
            }
            $lastmi = $lastmi ?? $mi;
            if ($loc) {
                $lastloc[] = $loc;
            }
        }
        if ($lastmi) {
            yield self::decorate_message_item($lastmi, $lastloc, $prevmi);
        }
    }

    function report() {
        $msgs = [];
        if ($this->_use_req && $this->has_error()) {
            $msgs[] = new MessageItem("", "<0>Your changes were not saved. Please fix these errors and try again.", MessageSet::PLAIN);
        }
        foreach ($this->decorated_message_list() as $mi) {
            $msgs[] = $mi;
        }
        $this->conf->feedback_msg($msgs);
    }

    function decorated_feedback_text() {
        return self::feedback_text($this->decorated_message_list());
    }

    /** @return SettingParser */
    function si_parser(Si $si) {
        return $this->cs()->callable($si->parser_class);
    }

    /** @param string $name
     * @return Si */
    function si($name) {
        if (($si = $this->conf->si($name))) {
            return $si;
        } else {
            throw new Exception(caller_landmark(2) . ": Unknown setting ‘{$name}’");
        }
    }

    /** @param string|Si $id
     * @return bool */
    function editable($id) {
        $si = is_string($id) ? $this->conf->si($id) : $id;
        if (!$si || !$si->configurable) {
            return false;
        }
        $perm = $this->all_perm;
        if ($this->perm !== null) {
            for ($i = 0; $i !== count($this->perm); $i += 2) {
                if ($si->has_tag($this->perm[$i])) {
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


    /** @param string|Si $id
     * @return mixed */
    function oldv($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        if (array_key_exists($si->name, $this->_explicit_oldv)
            || ($si->parser_class && $this->si_find_oldv($si))) {
            $val = $this->_explicit_oldv[$si->name];
        } else if (($si->storage_type & Si::SI_OPT) !== 0) {
            $val = $this->conf->opt(substr($si->storage_name(), 4)) ?? $si->default_value($this);
            if (($si->storage_type & Si::SI_VALUE) && is_bool($val)) {
                $val = (int) $val;
            }
        } else if (($si->storage_type & Si::SI_DATA) !== 0) {
            $val = $this->conf->setting_data($si->storage_name()) ?? $si->default_value($this);
        } else if (($si->storage_type & Si::SI_VALUE) !== 0) {
            $val = $this->conf->setting($si->storage_name()) ?? $si->default_value($this);
        } else if (($si->storage_type & Si::SI_MEMBER) !== 0) {
            $obj = $this->object_oldv($si->name0 . $si->name1);
            if (!$obj) {
                return null;
            }
            $val = $obj->{$si->storage_name()};
        } else {
            error_log("setting {$si->name}: don't know how to get value\n" . debug_string_backtrace());
            $val = $si->default_value($this);
        }
        if ($si->storage_type & Si::SI_NEGATE) {
            $val = $val ? 0 : 1;
        }
        return $val;
    }

    /** @param string|Si $id
     * @param mixed $value */
    function set_oldv($id, $value) {
        $n = is_string($id) ? $id : $id->name;
        $this->_explicit_oldv[$n] = $value;
    }

    /** @param Si $si
     * @return bool */
    private function si_find_oldv($si) {
        $this->si_parser($si)->set_oldv($si, $this);
        return array_key_exists($si->name, $this->_explicit_oldv);
    }

    /** @param string $name
     * @return ?object */
    private function object_oldv($name) {
        if (!array_key_exists($name, $this->_explicit_oldv)) {
            $si = $this->si($name);
            if ($si && $si->name0 !== null) {
                $this->ensure_oblist($si->name0);
            }
            if ($si && $si->parser_class && !array_key_exists($name, $this->_explicit_oldv)) {
                $this->si_parser($si)->set_oldv($si, $this);
            }
        }
        $v = $this->_explicit_oldv[$name] ?? null;
        return is_object($v) ? $v : null;
    }


    /** @return bool */
    function use_req() {
        return $this->_use_req;
    }

    /** @param string $name
     * @return bool */
    function has_req($name) {
        return array_key_exists($name, $this->req);
    }

    /** @param string $name
     * @return ?string */
    function reqstr($name) {
        return $this->req[$name] ?? null;
    }


    /** @param string|Si $id
     * @return string */
    function vstr($id) {
        if ($this->_use_req) {
            $name = is_string($id) ? $id : $id->name;
            if (array_key_exists($name, $this->req)) {
                return $this->req[$name];
            }
        }
        $si = is_string($id) ? $this->si($id) : $id;
        return $si->base_unparse_reqv($this->oldv($si), $this);
    }


    /** @param string|Si $id
     * @return mixed */
    function newv($id) {
        // XXX Beware: This function is inconsistent about whether it parses `$id`.
        // If `$id` refers to an object or an object member, then this function will
        // ensure that `$id` has been parsed, and return the new value.
        // If `$id` refers to something else, then this function will only return a
        // new value if `$id` has already been parsed.
        if ($this->_req_parse_state === 0) {
            return $this->oldv($id);
        }
        $si = is_string($id) ? $this->si($id) : $id;
        if ($si->type === "object") {
            return $this->object_newv($si->name);
        }
        assert($si->type !== "oblist");
        if (($si->storage_type & Si::SI_MEMBER) !== 0) {
            $oname = $si->name0 . $si->name1;
            if (!array_key_exists($si->name, $this->_explicit_newv)
                && !array_key_exists($oname, $this->_explicit_newv)
                && $this->has_req($si->name)) {
                $this->apply_req($si);
            }
            if (array_key_exists($si->name, $this->_explicit_newv)) {
                return $this->_explicit_newv[$si->name];
            } else if (($ov = $this->object_newv($oname))) {
                return $ov->{$si->storage_name()};
            } else {
                return $this->oldv($si);
            }
        }
        $sn = $si->storage_name();
        if (array_key_exists($sn, $this->_savedv)) {
            $vp = $this->_savedv[$sn];
            if ($vp === null) {
                $val = $si->default_value($this);
            } else {
                $val = $vp[($si->storage_type & Si::SI_DATA) !== 0 ? 1 : 0];
            }
            if (($si->storage_type & Si::SI_NEGATE) !== 0) {
                $val = $val ? 0 : 1;
            }
            return $val;
        }
        return $this->oldv($si);
    }

    /** @param string $name
     * @return object */
    function object_newv($name) {
        if (!array_key_exists($name, $this->_explicit_newv)) {
            if (($x = $this->object_oldv($name))) {
                $x = clone $x;
                // skip member parsing if object is deleted (avoid errors)
                if ($this->_use_req && $this->reqstr("{$name}/delete")) {
                    $x->deleted = true;
                } else {
                    $this->_object_parsingv[$name] = $x;
                    foreach ($this->req_member_list($name) as $si) {
                        $this->apply_req($si);
                    }
                    unset($this->_object_parsingv[$name]);
                }
            }
            $this->_explicit_newv[$name] = $x;
        }
        return $this->_explicit_newv[$name];
    }


    /** @param string|Si $id
     * @param bool $new
     * @return mixed */
    function choosev($id, $new) {
        return $new ? $this->newv($id) : $this->oldv($id);
    }


    /** @param string|Si $id
     * @param bool $new
     * @return mixed */
    function json_choosev($id, $new) {
        $si = is_string($id) ? $this->si($id) : $id;
        if ($si->type === "oblist") {
            // a member object list might be null rather than empty
            if (($si->storage_type & Si::SI_MEMBER) !== 0
                && ($ov = $this->choosev("{$si->name0}{$si->name1}", $new)) !== null
                && $ov->{$si->storage_name()} === null) {
                return null;
            }
            $a = [];
            foreach ($this->oblist_choose_keys($si->name, $new) as $ctr) {
                if ($new && $this->_use_req) {
                    $ov = $this->newv("{$si->name}/{$ctr}");
                    if ($ov && $ov->deleted)
                        continue;
                }
                $a[] = $this->json_choosev("{$si->name}/{$ctr}", $new);
            }
            return $a;
        }
        if ($si->type === "object") {
            $member_list = null;
            if ($si->parser_class) {
                $member_list = $this->si_parser($si)->member_list($si, $this);
            }
            if ($member_list === null) {
                $member_list = $this->conf->si_set()->member_list($si->name);
                usort($member_list, "Conf::xt_pure_order_compare");
            }
            $o = [];
            foreach ($member_list as $msi) {
                if ($msi->json_export()
                    && ($v = $this->json_choosev($msi, $new)) !== null) {
                    $member = $msi->name2 === "" ? $msi->name1 : substr($msi->name2, 1);
                    $o[$member] = $v;
                }
            }
            return (object) $o;
        }
        return $si->base_unparse_jsonv($this->choosev($si, $new), $this);
    }

    /** @param array{new?:bool,reset?:?bool} $args
     * @return object */
    function all_jsonv($args = []) {
        $new = $args["new"] ?? false;
        $j = [];
        if ($args["reset"] ?? false) {
            $j["reset"] = true;
        }
        foreach ($this->conf->si_set()->top_list() as $si) {
            if ($si->json_export()
                && ($v = $this->json_choosev($si, $new)) !== null) {
                $j[$si->name] = $v;
            }
        }
        return (object) $j;
    }


    /** @param string $pfx */
    private function ensure_oblist($pfx) {
        while (!isset($this->_oblist_ensured[$pfx])) {
            $this->_oblist_ensured[$pfx] = true;
            if (str_ends_with($pfx, "/")) {
                $pfx = substr($pfx, 0, -1);
            } else {
                $si = $this->conf->si($pfx);
                if ($si && $si->type === "oblist") {
                    $this->si_parser($si)->prepare_oblist($si, $this);
                    break;
                } else if ($si && $si->type === "object") {
                    $pfx = $si->name0;
                } else {
                    break;
                }
            }
        }
    }

    /** @param string $pfx
     * @param iterable<object> $obs
     * @param ?non-empty-string $namekey */
    function append_oblist($pfx, $obs, $namekey = null) {
        assert(!str_ends_with($pfx, "/"));

        // find next counter
        if (($nextctr = $this->_oblist_next[$pfx] ?? 0) === 0) {
            $nextctr = 1;
            if ($this->_use_req) {
                while (true) {
                    if ($this->has_req("{$pfx}/{$nextctr}/id")) {
                        ++$nextctr;
                    } else if ($namekey !== null
                               && $this->has_req("{$pfx}/{$nextctr}/{$namekey}")) {
                        // ensure `id` key exists
                        $this->set_req("{$pfx}/{$nextctr}/id", "");
                        ++$nextctr;
                    } else {
                        break;
                    }
                }
            }
        }

        // decide whether to mark unmentioned objects as deleted
        if ($this->_use_req) {
            $resetn = $this->has_req("{$pfx}_reset") ? "{$pfx}_reset" : "reset";
            $resets = $this->reqstr($resetn) ?? "";
            $reset = $resets !== "" && $resets !== "0";
        } else {
            $reset = false;
        }

        // map id => ctr and name => ctr
        $matches = $name_matches = [];
        for ($ctr = 1; $ctr < $nextctr; ++$ctr) {
            if (($id = $this->reqstr("{$pfx}/{$ctr}/id") ?? "") !== ""
                && !array_key_exists($id, $matches)) {
                $matches[$id] = $ctr;
            } else if ($namekey !== null
                       && ($name = $this->reqstr("{$pfx}/{$ctr}/{$namekey}")) !== null
                       && ($lname = strtolower($name)) !== ""
                       && !array_key_exists($lname, $name_matches)) {
                $name_matches[$lname] = $ctr;
            }
        }

        // iterate over objects, matching by id
        $ctrmap = $this->_oblist_ctrmap[$pfx] ?? [];
        $ctrmap_delta = count($ctrmap);
        $next_obs = [];
        foreach ($obs as $i => $ob) {
            if (($ob->id ?? "") !== ""
                && ($obctr = $matches[(string) $ob->id] ?? null) !== null) {
                $this->set_oldv("{$pfx}/{$obctr}", $ob);
                $ctrmap[$obctr] = $i + $ctrmap_delta;
            } else {
                $next_obs[$i] = $ob;
            }
        }

        // map name => ctr if any
        if (!empty($name_matches) && !empty($next_obs)) {
            $next_obs2 = [];
            foreach ($next_obs as $i => $ob) {
                if (($name = $ob->$namekey ?? "") !== ""
                    && ($obctr = $name_matches[strtolower($name)] ?? null) !== null) {
                    $this->set_req("{$pfx}/{$obctr}/id", (string) $ob->id);
                    $this->set_oldv("{$pfx}/{$obctr}/id", $ob->id);
                    $this->set_oldv("{$pfx}/{$obctr}", $ob);
                    $ctrmap[$obctr] = $i + $ctrmap_delta;
                } else {
                    $next_obs2[$i] = $ob;
                }
            }
            $next_obs = $next_obs2;
        }

        // assign remaining objects sequentially to ID-less inputs
        // (only if name matches are allowed and resetting)
        if ($namekey !== null && $reset && !empty($next_obs)) {
            $next_obs2 = [];
            $obctr = 1;
            foreach ($next_obs as $i => $ob) {
                while ($obctr < $nextctr
                       && ($this->reqstr("{$pfx}/{$obctr}/id") ?? "") !== "") {
                    ++$obctr;
                }
                if ($obctr < $nextctr) {
                    $this->set_req("{$pfx}/{$obctr}/id", (string) $ob->id);
                    $this->set_oldv("{$pfx}/{$obctr}/id", $ob->id);
                    $this->set_oldv("{$pfx}/{$obctr}", $ob);
                    $ctrmap[$obctr] = $i + $ctrmap_delta;
                    ++$obctr;
                } else {
                    $next_obs2[$i] = $ob;
                }
            }
            $next_obs = $next_obs2;
        }

        // save new objects
        foreach ($next_obs as $i => $ob) {
            $this->set_req("{$pfx}/{$nextctr}/id", (string) $ob->id);
            $this->set_oldv("{$pfx}/{$nextctr}/id", $ob->id);
            if ($reset) {
                $this->set_req("{$pfx}/{$nextctr}/delete", "1");
            } else {
                unset($this->req["{$pfx}/{$nextctr}/delete"]);
            }
            $this->set_oldv("{$pfx}/{$nextctr}", $ob);
            $ctrmap[$nextctr] = $i + $ctrmap_delta;
            ++$nextctr;
        }

        unset($this->req["{$pfx}/{$nextctr}/id"]);
        $this->_oblist_next[$pfx] = $nextctr;
        $this->_oblist_ctrmap[$pfx] = $ctrmap;
    }

    /** @param string $pfx
     * @return list<int> */
    function oblist_keys($pfx) {
        $this->ensure_oblist($pfx);
        $ctrs = [];
        for ($ctr = 1; array_key_exists("{$pfx}/{$ctr}/id", $this->req); ++$ctr) {
            $ctrs[] = $ctr;
        }
        if ($this->conf->si("{$pfx}/1/order")) {
            usort($ctrs, function ($a, $b) use ($pfx) {
                $ao = $this->vstr("{$pfx}/{$a}/order");
                $an = is_numeric($ao);
                $bo = $this->vstr("{$pfx}/{$b}/order");
                $bn = is_numeric($bo);
                if ($an && $bn) {
                    return floatval($ao) <=> floatval($bo);
                } else if ($an || $bn) {
                    return $an ? -1 : 1;
                } else {
                    return $a <=> $b;
                }
            });
        }
        return $ctrs;
    }

    /** @param string $pfx
     * @param bool $new
     * @return list<int> */
    function oblist_choose_keys($pfx, $new) {
        $ctrs = $this->oblist_keys($pfx);
        if (!$new && isset($this->_oblist_ctrmap[$pfx])) {
            asort($this->_oblist_ctrmap[$pfx]);
            $ctrs = array_keys($this->_oblist_ctrmap[$pfx]);
        }
        return $ctrs;
    }

    /** @param string $pfx
     * @return list<int> */
    function oblist_nondeleted_keys($pfx) {
        $ctrs = [];
        foreach ($this->oblist_keys($pfx) as $ctr) {
            if (!$this->reqstr("{$pfx}/{$ctr}/delete"))
                $ctrs[] = $ctr;
        }
        return $ctrs;
    }

    /** @param string $pfx
     * @param string $sfx
     * @param int|string $needle
     * @return ?int */
    function search_oblist($pfx, $sfx, $needle) {
        assert(!str_ends_with($pfx, "/") && !str_starts_with($sfx, "/"));
        $this->ensure_oblist($pfx);
        for ($ctr = 1; array_key_exists("{$pfx}/{$ctr}/id", $this->req); ++$ctr) {
            if ((string) $needle === (string) $this->req["{$pfx}/{$ctr}/{$sfx}"]) {
                return $ctr;
            }
        }
        return null;
    }

    /** @param string $pfx
     * @param int|string $ctr
     * @param string $sfx
     * @param string $description
     * @param bool $case_sensitive
     * @return bool */
    function error_if_duplicate_member($pfx, $ctr, $sfx, $description, $case_sensitive = false) {
        // NB: $pfx may or may not end with `/`; $sfx may or may not begin with `/`
        if (!str_ends_with($pfx, "/")) {
            $pfx .= "/";
        }
        if (!str_starts_with($sfx, "/")) {
            $sfx = "/{$sfx}";
        }
        assert(is_int($ctr) || (is_string($ctr) && ctype_digit($ctr)));
        $ctr = (int) $ctr;
        if ($this->reqstr("{$pfx}{$ctr}/delete")) {
            return false;
        }
        $oim = $this->swap_ignore_messages(true);
        $collator = $case_sensitive ? $this->conf->collator() : $this->_icollator;
        $v0 = $this->base_parse_req("{$pfx}{$ctr}{$sfx}");
        $badctr = null;
        for ($ctr1 = $ctr + 1; array_key_exists("{$pfx}{$ctr1}/id", $this->req); ++$ctr1) {
            if (!$this->reqstr("{$pfx}{$ctr1}/delete")
                && ($v1 = $this->base_parse_req("{$pfx}{$ctr1}{$sfx}")) !== null
                && $v0 !== null
                && $collator->compare($v0, $v1) === 0) {
                $badctr = $ctr1;
                break;
            }
        }
        $this->swap_ignore_messages($oim);
        if ($badctr !== null) {
            $v0 = $v0 === "" ? "(empty)" : $v0;
            $this->error_at("{$pfx}{$ctr}{$sfx}", "<0>{$description} ‘{$v0}’ is not unique");
            $this->error_at("{$pfx}{$badctr}{$sfx}");
            return true;
        } else {
            return false;
        }
    }

    /** @param string|Si ...$fields */
    function error_if_missing(...$fields) {
        foreach ($fields as $field) {
            $name = is_string($field) ? $field : $field->name;
            if (!$this->has_req($name)
                && $this->vstr($name) === ""
                && !$this->has_error_at($name))
                $this->error_at($name, "<0>Entry required");
        }
    }

    /** @param list<string> $list1
     * @param list<string> $list2
     * @return array<int,int> */
    function unambiguous_renumbering($list1, $list2) {
        $collator = $this->conf->collator();
        $nlist2 = count($list2);
        $map1 = array_fill(0, count($list1), []);
        $map2c = array_fill(0, $nlist2, 0);
        $n2unique = 0;
        for ($i = 0; $i !== count($list1); ++$i) {
            for ($j = 0; $j !== $nlist2; ++$j) {
                if ($collator->compare($list1[$i], $list2[$j]) === 0) {
                    $map1[$i][] = $j;
                    ++$map2c[$j];
                    if ($map2c[$j] === 1) {
                        ++$n2unique;
                    } else if ($map2c[$j] === 2) {
                        --$n2unique;
                    }
                }
            }
        }
        $state = [];
        foreach ($map1 as $jlist) {
            if (count($jlist) === 1 && $map2c[$jlist[0]] === 1) {
                $state[] = 1; // has a unique mapping
            } else if (empty($jlist) && $n2unique === $nlist2) {
                $state[] = -1; // should be deleted
            } else {
                $state[] = 0;
            }
        }
        $map = [];
        foreach ($map1 as $i => $jlist) {
            if ($state[$i] === 1
                && ($jlist[0] >= count($list1) || $state[$jlist[0]] !== 0)
                && $i !== $jlist[0]) {
                $map[$i] = $jlist[0];
            } else if ($state[$i] === -1 || $i >= $nlist2) {
                $map[$i] = -1;
            }
        }
        return $map;
    }


    /** @param string|Si $id
     * @return bool */
    function has_interest($id) {
        if (!$this->canonical_page || $this->all_interest) {
            return true;
        } else if (($si = is_string($id) ? $this->conf->si($id) : $id)) {
            return $si->has_tag($this->canonical_page)
                || array_key_exists($si->storage_name(), $this->_savedv);
        } else {
            return false;
        }
    }


    /** @param string $field
     * @return string */
    function feedback_at($field) {
        $fname = $field instanceof Si ? $field->name : $field;
        return $this->feedback_html_at($fname);
    }

    /** @param string $field */
    function print_feedback_at($field) {
        echo $this->feedback_at($field);
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
                $this->error_at($name0, "<5>Must come before " . $this->setting_link($si1->title_html($this), $si1));
                $this->error_at($name1);
                return false;
            }
        }
        return true;
    }

    /** @param string $name
     * @param bool $use_default
     * @return array{subject:string,body:string} */
    function expand_mail_template($name, $use_default) {
        if (!$this->_null_mailer) {
            $this->_null_mailer = new HotCRPMailer($this->conf, null, ["width" => false]);
        }
        return $this->_null_mailer->expand_template($name, $use_default);
    }

    /** @return Tagger */
    function tagger() {
        $this->_tagger = $this->_tagger ?? new Tagger($this->user);
        return $this->_tagger;
    }


    /** @param string|Si $id
     * @return void */
    function save($id, $value) {
        // check that storage is allowed
        $si = is_string($id) ? $this->si($id) : $id;
        if (!$si || $si->storage_type === Si::SI_NONE) {
            $name = is_string($id) ? $id : $si->name;
            error_log("setting {$name}: no setting or cannot save value");
            return;
        }

        // check that value is valid for saving type
        $member = ($si->storage_type & Si::SI_MEMBER) !== 0;
        $sn = $si->storage_name();
        if ($value === null) {
            error_log("setting {$si->name}: setting value to null: " . debug_string_backtrace());
        } else if (!$member
                   && (($si->storage_type & Si::SI_DATA) !== 0
                       ? !is_string($value)
                       : !is_int($value) && !is_bool($value))) {
            error_log(caller_landmark() . ": setting {$si->name}: invalid value " . var_export($value, true));
            return;
        }

        // adapt value to storage requirements
        if ($si->storage_type & Si::SI_NEGATE) {
            $value = !$value;
        }
        if ($si->value_nullable($value, $this)) {
            $value = null;
        }

        // save to member
        if ($member) {
            $ov = $this->_object_parsingv[$si->name0 . $si->name1] ?? null;
            if (!$ov) {
                $this->_explicit_newv[$si->name] = $value;
            } else if ($value === null && $si->value_nullable($ov->{$sn}, $this)) {
                // special case: do not write `null` over a nullable member
            } else {
                $ov->{$sn} = $value;
            }
            return;
        }

        // save to _savedv
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        if (($si->storage_type & Si::SI_SLICE) !== 0) {
            if (array_key_exists($sn, $this->_savedv)) {
                $vp = $this->_savedv[$sn] ?? [0, null];
            } else {
                $vp = [$this->conf->setting($sn) ?? 0, $this->conf->setting_data($sn)];
            }
            if (($si->storage_type & Si::SI_VALUE) !== 0) {
                $vp[0] = $value ?? 0;
            } else {
                $vp[1] = $value;
            }
            if ($vp === [0, null]) {
                $vp = null;
            }
        } else if ($value === null) {
            $vp = null;
        } else if (($si->storage_type & Si::SI_VALUE) !== 0) {
            $vp = [$value, null];
        } else {
            $vp = [1, $value];
        }
        $this->_savedv[$sn] = $vp;
    }

    /** @param string|Si $id
     * @return bool */
    function update($id, $value) {
        if ($value !== $this->oldv($id)) {
            $this->save($id, $value);
            return true;
        } else {
            return false;
        }
    }

    /** @param string|Si $id
     * @return void
     * @deprecated */
    function unsave($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        assert($si->storage_type !== Si::SI_NONE
               && ($si->storage_type & (Si::SI_MEMBER | Si::SI_SLICE)) === 0);
        unset($this->_savedv[$si->storage_name()]);
    }


    /** @return SettingValuesConf */
    function make_svconf() {
        return new SettingValuesConf($this);
    }

    /** @param string $name
     * @param 0|1 $idx */
    function __saved_setting($name, $idx) {
        if (array_key_exists($name, $this->_savedv)) {
            $sv = $this->_savedv[$name] ?? [0, null];
            return $sv[$idx];
        } else if ($idx === 0) {
            return $this->conf->setting($name);
        } else {
            return $this->conf->setting_data($name);
        }
    }

    /** @param string $name */
    function __saved_opt($name) {
        $svkey = "opt.{$name}";
        if (array_key_exists($svkey, $this->_savedv)) {
            $sv = $this->_savedv[$svkey] ?? [0, null];
            $idx = Si::$option_is_value[$name] ? 0 : 1;
            return $sv[$idx];
        } else {
            return $this->conf->opt($name);
        }
    }


    /** @param string|Si $id
     * @return null|int|string */
    function base_parse_req($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        if ($this->has_req($si->name)) {
            return $si->parse_reqv($this->reqstr($si->name), $this);
        } else {
            return $this->oldv($si);
        }
    }

    /** @param Si $si */
    function apply_req($si) {
        if (!$si->internal
            && $this->editable($si)
            && (!$si->parser_class
                || $this->si_parser($si)->apply_req($si, $this) === false)
            && $si->storage_type !== Si::SI_NONE
            && ($value = $si->parse_reqv($this->reqstr($si->name), $this)) !== null) {
            $this->save($si, $value);
        }
    }

    /** @return $this */
    function parse() {
        assert($this->_req_parse_state === 0);
        assert($this->_use_req);
        $this->_req_parse_state = 1;
        $siset = $this->conf->si_set();
        $this->_new_req = array_keys($this->req);
        $i = 0;
        $req_si = [];

        while (true) {
            // add newly requested settings
            foreach ($this->_new_req as $k) {
                if (strpos($k, "/") === false
                    && ($si = $siset->get($k))
                    && $si->name === $k /* skip aliases */) {
                    if ($i !== 0) {
                        $req_si = array_slice($req_si, $i);
                        $i = 0;
                    }
                    $req_si[] = $si;
                }
            }
            $this->_new_req = [];
            // exit after processing all settings
            $n = count($req_si);
            if ($i === $n) {
                break;
            }
            // process settings in parse order
            if ($i === 0) { /* sort or resort */
                usort($req_si, "Si::parse_order_compare");
            }
            while ($i !== $n && empty($this->_new_req)) {
                $this->apply_req($req_si[$i]);
                ++$i;
            }
        }

        $this->_req_parse_state = 2;
        return $this;
    }

    /** @param string $pfx
     * @return list<Si> */
    function req_member_list($pfx) {
        assert($this->_req_parse_state !== 0 && !str_ends_with($pfx, "/"));
        if (!$this->_req_sorted) {
            ksort($this->req, SORT_STRING);
            $this->_req_sorted = true;
        }
        $reqkeys = array_keys($this->req);
        $xpfx = "{$pfx}/";
        $l = str_list_lower_bound($xpfx, $reqkeys);
        $sis = [];
        $siset = $this->conf->si_set();
        while ($l < count($reqkeys) && str_starts_with($reqkeys[$l], $xpfx)) {
            if (strpos($reqkeys[$l], "/", strlen($xpfx)) === false
                && ($si = $siset->get($reqkeys[$l]))
                && $si->name === $reqkeys[$l]) {
                $sis[] = $si;
            }
            ++$l;
        }
        usort($sis, "Si::parse_order_compare");
        return $sis;
    }


    /** @return bool */
    function execute() {
        assert($this->_req_parse_state !== 1);
        if ($this->_req_parse_state === 0) {
            $this->parse();
        }

        // obtain locks
        $this->request_read_lock("ContactInfo");

        // make settings
        $this->_diffs = [];
        if (!$this->has_error()
            && (!empty($this->_savedv) || !empty($this->_saved_si))) {
            $tables = "Settings write";
            foreach ($this->_table_lock as $t => $need) {
                $tables .= ", $t " . ($need < 2 ? "read" : "write");
            }
            $this->conf->qe_raw("lock tables {$tables}");
            $this->conf->delay_logs();

            // load db settings, pre-crosscheck
            $dbsettings = [];
            $result = $this->conf->qe("select name, value, data from Settings");
            while (($row = $result->fetch_row())) {
                $row[1] = isset($row[1]) ? (int) $row[1] : null;
                $dbsettings[$row[0]] = $row;
            }
            Dbl::free($result);

            // apply settings
            foreach ($this->_saved_si as $si) {
                $this->si_parser($si)->store_value($si, $this);
            }

            $dv = $av = [];
            foreach ($this->_savedv as $n => $v) {
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
                    if ($oldv === $newv
                        || ($vi === 0 && is_bool($oldv) && (int) $oldv === $newv)) {
                        $v = null; // delete override value in database
                    } else if ($v === null && $oldv !== $basev && $oldv !== null) {
                        $v = $vi ? [0, ""] : [0, null];
                    }
                }
                if ($v === null
                    ? !isset($dbsettings[$n])
                    : isset($dbsettings[$n]) && $dbsettings[$n][1] === $v[0] && $dbsettings[$n][2] === $v[1]) {
                    continue;
                }
                //error_log("{$n}: " . json_encode($dbsettings[$n][1] ?? null) . "=>" . json_encode($v[0] ?? null) . "; " . json_encode($dbsettings[$n][2] ?? null) . "=>" . json_encode($v[1] ?? null));
                if (!isset($this->_no_diffs[$n])) {
                    $this->_diffs[$n] = true;
                }
                if ($v !== null) {
                    $av[] = [$n, $v[0], $v[1]];
                } else {
                    $dv[] = $n;
                }
            }
            if (!empty($dv)) {
                $this->conf->qe("delete from Settings where name?a", $dv);
                //Conf::msg_debugt(Dbl::format_query("delete from Settings where name?a", $dv));
            }
            if (!empty($av)) {
                $this->conf->qe("insert into Settings (name, value, data) values ?v ?U on duplicate key update value=?U(value), data=?U(data)", $av);
                //Conf::msg_debugt(Dbl::format_query("insert into Settings (name, value, data) values ?v ?U on duplicate key update value=?U(value), data=?U(data)", $av));
            }

            $this->conf->qe_raw("unlock tables");
            $this->conf->release_logs();
            if (!empty($this->_diffs)) {
                $this->user->log_activity("Settings edited: " . join(", ", array_keys($this->_diffs)));
            }

            // clean up
            $this->conf->load_settings();
            foreach ($this->_cleanup_callbacks as $cba) {
                $cb = $cba[1];
                $cb();
            }
            if (!empty($this->_invalidate_caches)) {
                $this->conf->invalidate_caches($this->_invalidate_caches);
            }
        }
        return !$this->has_error();
    }


    /** @param string $siname */
    function mark_diff($siname)  {
        $this->_diffs[$siname] = true;
    }

    /** @param string $siname */
    function mark_no_diff($siname)  {
        $this->_no_diffs[$siname] = false;
    }

    /** @param string $siname
     * @return bool */
    function has_diff($siname) {
        return $this->_diffs[$siname] ?? false;
    }

    /** @param associative-array<string,true> $caches */
    function mark_invalidate_caches($caches) {
        foreach ($caches as $c => $t) {
            $this->_invalidate_caches[$c] = true;
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
        $this->_saved_si[] = $si;
    }

    /** @param ?string $name
     * @param callable() $func */
    function register_cleanup_function($name, $func) {
        if ($name !== null) {
            foreach ($this->_cleanup_callbacks as $cb) {
                if ($cb[0] === $name)
                    return;
            }
        }
        $this->_cleanup_callbacks[] = [$name, $func];
    }

    /** @return list<string> */
    function changed_keys() {
        return array_keys($this->_diffs);
    }


    /** @return bool */
    function inputs_printed() {
        return $this->_inputs_printed;
    }

    function mark_inputs_printed() {
        $this->_inputs_printed = true;
    }

    /** @param string $html
     * @param string|Si $id
     * @return string */
    function setting_link($html, $id, $js = null) {
        $si = is_string($id) ? $this->si($id) : $id;
        if ($this->link_json && ($jpath = $si->json_path())) {
            return $this->json_path_link($html, $jpath, $js);
        } else {
            return Ht::link($html, $si->sv_hoturl($this), $js);
        }
    }

    /** @param string $html
     * @param string $jpath
     * @return string */
    function json_path_link($html, $jpath, $js = null) {
        $lpfx = $html !== "" ? "<u>{$html}</u> " : "";
        $hjpath = htmlspecialchars($jpath);
        $lpath = "<code class=\"settings-jpath\">{$hjpath}</code>";
        return "<a href=\"\" class=\"ui js-settings-jpath noul\">{$lpfx}{$lpath}</a>";
    }

    /** @param string $html
     * @param string $sg
     * @return string */
    function setting_group_link($html, $sg, $js = null) {
        $gj = $this->group_item($sg);
        if ($gj) {
            $page = $this->cs()->canonical_group($gj);
            if ($page === $this->canonical_page && ($gj->hashid ?? false)) {
                $url = "#" . $gj->hashid;
            } else {
                $url = $this->conf->hoturl("settings", ["group" => $page, "#" => $gj->hashid ?? null]);
            }
            return Ht::link($html, $url, $js);
        } else {
            error_log("missing setting_group information for $sg\n" . debug_string_backtrace());
            return $html;
        }
    }

    /** @param string $type
     * @return string */
    function type_hint($type) {
        if ($type && str_ends_with($type, "date") && !isset($this->_hint_status["date"])) {
            $this->_hint_status["date"] = true;
            return "Date examples: ‘now’, ‘10 Dec 2006 11:59:59pm PST’, ‘2019-10-31 UTC-1100’, ‘Dec 31 AoE’ <a href=\"http://php.net/manual/en/datetime.formats.php\">(more examples)</a>";
        } else if ($type === "grace" && !isset($this->_hint_status["grace"])) {
            $this->_hint_status["grace"] = true;
            return "Example: ‘15 min’";
        } else {
            return "";
        }
    }

    /** @return string */
    function label($name, $html, $label_js = []) {
        $name1 = is_array($name) ? $name[0] : $name;
        if (($label_js["class"] ?? null) === false
            || ($label_js["no_control_class"] ?? false)) {
            unset($label_js["no_control_class"]);
        } else {
            foreach (is_array($name) ? $name : [$name] as $n) {
                if (($sc = $this->control_class($n))) {
                    $label_js["class"] = Ht::add_tokens($sc, $label_js["class"] ?? null);
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

    /** @param string|Si $id
     * @param ?array<string,mixed> $js
     * @return array<string,mixed> */
    function sjs($id, $js = null) {
        $si = is_string($id) ? $this->conf->si($id) : $id;
        $name = $si ? $si->name : $id;
        $x = ["id" => $name];
        if ($si
            && !isset($js["disabled"])
            && !isset($js["readonly"])
            && !$this->editable($si)) {
            if (in_array($si->type, ["checkbox", "radio", "select", "cdate", "tagselect"], true)) {
                $x["disabled"] = true;
            } else {
                $x["readonly"] = true;
            }
        }
        if ($this->_use_req
            && !isset($js["data-default-value"])
            && !isset($js["data-default-checked"])) {
            if ($si && $this->has_interest($si)) {
                $x["data-default-value"] = $si->base_unparse_reqv($this->oldv($si), $this);
            } else if (isset($this->_explicit_oldv[$name])) {
                $x["data-default-value"] = $this->_explicit_oldv[$name];
            }
        }
        foreach ($js ?? [] as $k => $v) {
            if (strlen($k) >= 10
                ? $k !== "horizontal" && strpos($k, "_") === false
                : $k !== "hint")
                $x[$k] = $v;
        }
        if ($this->has_problem_at($name)) {
            $x["class"] = $this->control_class($name, $x["class"] ?? "");
        }
        if (isset($js["fold_values"])) {
            $x["class"] = Ht::add_tokens($x["class"] ?? "", "uich js-foldup");
        }
        return $x;
    }

    /** @param string|Si $name
     * @param string $class
     * @param ?array<string,mixed> $js */
    function print_group_open($name, $class, $js = null) {
        $si = is_string($name) ? $this->si($name) : $name;
        $xjs = ["class" => $class];
        if (!isset($js["no_control_class"])) {
            if (isset($js["feedback_items"])) {
                $xjs["class"] = self::status_class(self::list_status($js["feedback_items"]), $xjs["class"]);
            } else {
                $xjs["class"] = $this->control_class($si->name, $xjs["class"]);
            }
        }
        if (isset($js["group_class"])) {
            $xjs["class"] = Ht::add_tokens($xjs["class"], $js["group_class"]);
        }
        if (isset($js["fold_values"]) && !empty($js["fold_values"])) {
            $fv = $js["fold_values"];
            assert(is_array($fv));
            $fold = "fold" . (in_array($this->vstr($si->name), $fv) ? "o" : "c");
            $xjs["class"] = Ht::add_tokens($xjs["class"], "has-fold {$fold}");
            $xjs["data-fold-values"] = join(" ", $fv);
        }
        if (isset($js["group_attr"])) {
            $xjs = $xjs + $js["group_attr"];
        }
        if (isset($js["group_id"]) && !isset($xjs["id"])) {
            $xjs["id"] = $js["group_id"];
        }
        echo '<div', Ht::extra($xjs), '>';
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function print_checkbox_only($name, $js = null) {
        $js["id"] = $name;
        echo Ht::hidden("has_$name", 1),
            Ht::checkbox($name, 1, !!$this->vstr($name), $this->sjs($name, $js));
    }

    /** @param string $name
     * @param string $text
     * @param ?array<string,mixed> $js
     * @return void */
    function print_checkbox($name, $text, $js = null) {
        $js = $js ?? [];
        $this->print_group_open($name, "checki", $js + ["no_control_class" => true]);
        echo '<span class="checkc">';
        $this->print_checkbox_only($name, $js);
        echo '</span>', $this->label($name, $text, ["for" => $name, "class" => $js["label_class"] ?? null]);
        $this->print_feedback_at($name);
        if (($hint = $js["hint"] ?? "")) {
            echo '<div class="', Ht::add_tokens("settings-ap f-hx", $js["hint_class"] ?? null), '">', $hint, '</div>';
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
    function print_radio_table($name, $varr, $heading = null, $rest = []) {
        $x = $this->vstr($name);
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

        $this->print_group_open($name, "settings-radio", $rest + ["group_id" => $name]);
        if ($heading) {
            echo '<div class="label">', $heading, '</div>';
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
        $this->print_feedback_at($name);
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
        $v = $js["value"] ?? $this->vstr($si);
        $js = $this->sjs($si, $js ?? []);
        if (!isset($js["size"])
            && $si->size) {
            $js["size"] = $si->size;
        }
        if (!isset($js["placeholder"])
            && ($placeholder = $si->placeholder($this)) !== null) {
            $js["placeholder"] = $placeholder;
        }
        if ($si->autogrow) {
            $js["class"] = ltrim(($js["class"] ?? "") . " need-autogrow");
        }
        if (($dv = $si->default_value($this)) !== null
            && isset($js["placeholder"])
            && $v === (string) $dv) {
            $v = "";
        }
        return Ht::entry($name, $v, $js);
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function print_entry($name, $js = null) {
        echo $this->entry($name, $js);
    }

    /** @param string|Si $id
     * @param string $description
     * @param string $control
     * @param ?array<string,mixed> $js */
    function print_control_group($id, $description, $control, $js = null) {
        $si = is_string($id) ? $this->si($id) : $id;
        $horizontal = !!($js["horizontal"] ?? false);
        $this->print_group_open($si->name, $horizontal ? "entryi" : "f-i", $js);

        if ($description === null) {
            $description = $si->title_html($this);
        }
        echo $this->label($si->name, $description, [
            "class" => $js["label_class"] ?? null,
            "no_control_class" => true
        ]);
        if ($horizontal) {
            echo '<div class="entry">';
        }
        if (isset($js["feedback_items"])) {
            echo MessageSet::feedback_html($js["feedback_items"]);
        } else {
            $this->print_feedback_at($si->name);
        }
        echo $control, $js["control_after"] ?? "";
        $hint = $js["hint"] ?? "";
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
        if (!($js["group_open"] ?? null)) {
            $this->print_close_control_group($js);
        }
    }

    /** @param ?array<string,mixed> $js
     * @return void */
    function print_close_control_group($js) {
        $horizontal = !!($js["horizontal"] ?? false);
        echo $horizontal ? "</div></div>\n" : "</div>\n";
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function print_entry_group($name, $description, $js = null) {
        $this->print_control_group($name, $description,
            $this->entry($name, $js), $js);
    }

    /** @param string $name
     * @param array $values
     * @param ?array<string,mixed> $js
     * @return string */
    function select($name, $values, $js = null) {
        $si = $this->si($name);
        $v = $this->vstr($si);
        return Ht::select($name, $values, $v ?? "0", $this->sjs($si, $js));
    }

    /** @param string $name
     * @param string $description
     * @param array $values
     * @param ?array<string,mixed> $js */
    function print_select_group($name, $description, $values, $js = null) {
        $this->print_control_group($name, $description,
            $this->select($name, $values, $js), $js);
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return string */
    function textarea($name, $js = null) {
        $si = $this->si($name);
        $v = $this->vstr($si);
        $js = $this->sjs($si, $js ?? []);
        if (!isset($js["placeholder"])
            && ($placeholder = $si->placeholder($this)) !== null) {
            $js["placeholder"] = $placeholder;
        }
        $js["class"] = $js["class"] ?? "w-entry-text";
        if ($si->autogrow ?? true) {
            $js["class"] = Ht::add_tokens($js["class"] ?? "", "need-autogrow");
        }
        if (!isset($js["rows"])) {
            $js["rows"] = $si->size ? : 10;
        }
        if (!isset($js["cols"])) {
            $js["cols"] = 80;
        }
        return Ht::textarea($name, $v, $js);
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return void */
    function print_textarea_group($name, $description, $js = null) {
        $this->print_control_group($name, $description,
            $this->textarea($name, $js), $js);
    }

    /** @param string $name
     * @param string $description
     * @param string $hint
     * @param string $xclass */
    private function print_message_base($name, $description, $hint, $xclass) {
        $si = $this->si($name);
        $current = $this->vstr($si);
        $description = '<button type="button" class="q ui js-foldup">'
            . expander(null, 0) . $description . '</button>';
        echo '<div class="f-i has-fold fold',
            ($current == $si->default_value($this) ? "c" : "o"), '">',
            '<div class="f-c', $xclass, ' ui js-foldup">',
            $this->label($name, $description),
            ' <span class="n fx">(HTML allowed)</span></div>',
            $this->feedback_at($name),
            $this->textarea($name, ["class" => "fx w-text"]),
            $hint, "</div>\n";
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function print_message($name, $description, $hint = "") {
        $this->print_message_base($name, $description, $hint, "");
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function print_message_minor($name, $description, $hint = "") {
        $this->print_message_base($name, $description, $hint, " n");
    }

    /** @param string $name
     * @param string $description
     * @param string $hint */
    function print_message_horizontal($name, $description, $hint = "") {
        $si = $this->si($name);
        $current = $this->vstr($si);
        if ($current !== $si->default_value($this)) {
            echo '<div class="entryi">', $this->label($name, $description), '<div>';
            $close = "";
        } else {
            $description = '<button type="button" class="q ui js-foldup">'
                . expander(null, 0) . $description . '</button>';
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
}


class SettingValuesConf {
    /** @var SettingValues */
    private $sv;
    function __construct(SettingValues $sv) {
        $this->sv = $sv;
    }
    /** @param string $name
     * @return ?int */
    function setting($name) {
        return $this->sv->__saved_setting($name, 0);
    }
    /** @param string $name
     * @return ?string */
    function setting_data($name) {
        return $this->sv->__saved_setting($name, 1);
    }
    /** @param string $name */
    function opt($name) {
        return $this->sv->__saved_opt($name);
    }
}
