<?php
// settingvalues.php -- HotCRP conference settings manager
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SettingValues extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var ?string */
    public $canonical_page;
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
    /** @var bool */
    private $_req_parsed = false;
    /** @var list<Si> */
    private $_req_si;

    /** @var array<string,true> */
    private $_hint_status = [];
    /** @var ?Mailer */
    private $_null_mailer;
    /** @var ?Tagger */
    private $_tagger;

    /** @var array<string,mixed> */
    private $_explicit_oldv = [];
    /** @var array<string,true> */
    private $_ensure_enumerations = [];

    /** @var ?object */
    public $cur_object;
    /** @var array<string,array{?int,?string}> */
    private $_savedv = [];
    /** @var list<Si> */
    private $_saved_si = [];
    /** @var list<array{?string,callable()}> */
    private $_cleanup_callbacks = [];
    /** @var array<string,int> */
    private $_table_lock = [];
    /** @var associative-array<string,true> */
    private $_diffs = [];
    /** @var associative-array<string,true> */
    private $_invalidate_caches = [];

    /** @var ?ComponentSet */
    private $_cs;
    /** @var ?string */
    private $_jpath;
    /** @var ?JsonParser */
    private $_jp;

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
    }

    /** @param Qrequest|array<string,string|int|float> $qreq */
    static function make_request(Contact $user, $qreq) {
        return (new SettingValues($user))->add_request($qreq);
    }

    /** @param bool $x
     * @return $this */
    function set_use_req($x) {
        $this->_use_req = $x;
        return $this;
    }

    /** @param string $k
     * @param string $v
     * @return $this */
    function set_req($k, $v) {
        $this->req[$k] = $v;
        if (str_starts_with($k, "has_")) {
            $k = substr($k, 4);
            $this->req[$k] = $this->req[$k] ?? null;
        }
        return $this;
    }

    /** @param Qrequest|array<string,string|int|float> $qreq
     * @return $this */
    function add_request($qreq) {
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
     * @param bool $reset
     * @return $this */
    function add_json_string($jstr, $filename = null, $reset = false) {
        assert(!$this->_req_parsed);
        $this->_jp = (new JsonParser($jstr))->filename($filename);
        $j = $this->_jp->decode();
        if ($j !== null || $this->_jp->error_type === 0) {
            $this->_jpath = "";
            $this->set_json_parts("", $j, $reset);
        } else {
            $this->error_at(null, "<0>Invalid JSON at character position {$this->_jp->error_pos}");
        }
        $this->_jp = null;
        return $this;
    }

    /** @param string $parts
     * @param mixed $j
     * @param bool $reset */
    private function set_json_parts($parts, $j, $reset) {
        if (!is_object($j)) {
            $this->error_at(null, "<0>Expected JSON object");
            return;
        }
        $si_set = $this->conf->si_set();
        if (($jpath = $this->_jpath) !== "") {
            $jpath .= ".";
        }
        foreach ((array) $j as $k => $v) {
            $si = $si_set->get("{$parts}{$k}");
            $this->_jpath = "{$jpath}{$k}";
            if (!$si) {
                if ($k === "delete" && is_bool($v) && $parts !== "") {
                    if ($v === true) {
                        $this->set_req("{$parts}delete", "1");
                    }
                } else if ($k !== "" && $k[0] !== "\$" && $k[0] !== "#") {
                    $this->warning_at(null, "<0>Unknown setting");
                }
            } else if ($si->internal) {
                if (is_scalar($v)) {
                    $this->set_req($si->name, "{$v}");
                }
            } else if ($si->type === "objectlist") {
                if (!is_array($v)) {
                    $this->error_at(null, "<0>Array of JSON objects expected");
                } else {
                    $this->set_req("has_{$si->name}", "1");
                    if ($reset) {
                        $this->set_req("{$si->name}__reset", "1");
                    }
                    foreach ($v as $i => $vv) {
                        $this->_jpath = "{$jpath}{$k}[{$i}]";
                        $pfx = "{$si->name}__" . ($i + 1) . "__";
                        if (!array_key_exists("{$pfx}id", $this->req)) {
                            $this->req["{$pfx}id"] = "";
                        }
                        if (is_string($vv)) {
                            $this->req["{$pfx}name"] = $vv;
                        } else if (is_object($vv)) {
                            $this->set_json_parts($pfx, $vv, $reset);
                        } else {
                            $this->error_at(null, "<0>JSON object expected");
                        }
                    }
                }
            } else if ($si->type === "object") {
                if (!is_object($v)) {
                    $this->error_at(null, "<0>JSON object expected");
                } else {
                    $this->set_req("has_{$si->name}", "1");
                    $this->set_json_parts($si->name, $v, $reset);
                }
            } else if (($vstr = $si->convert_jsonv($v, $this)) !== null) {
                $this->set_req($si->name, $vstr);
            }
        }
    }

    function session_highlight() {
        foreach ($this->user->session("settings_highlight") ?? [] as $f => $v) {
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


    /** @return ComponentSet */
    function cs() {
        if ($this->_cs === null) {
            $this->_cs = new ComponentSet($this->user, ["etc/settinggroups.json"], $this->conf->opt("settingGroups"));
            $this->_cs->set_title_class("form-h")
                ->set_section_class("form-section")
                ->set_separator('<hr class="form-sep">')
                ->set_context_args([$this]);
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

    /** @param string $g
     * @param bool $top */
    function print_group($g, $top = false) {
        $this->cs()->print_group($g, $top);
    }

    /** @param string $title
     * @param ?string $hashid */
    function print_start_section($title, $hashid = null) {
        $this->cs()->print_start_section($title, $hashid);
    }

    /** @param string $title
     * @param ?string $hashid
     * @deprecated */
    function print_section($title, $hashid = null) {
        $this->print_start_section($title, $hashid);
    }


    /** @param MessageItem $mi
     * @return MessageItem */
    private function with_jpath($mi) {
        if ($this->_jpath !== "") {
            $mi = $mi->with_prefix($this->_jpath . ": ");
        }
        return $mi->with_landmark($this->_jp->path_landmark($this->_jpath));
    }

    /** @param null|string|Si $field
     * @param MessageItem $mi
     * @return MessageItem */
    function append_item_at($field, $mi) {
        if ($this->_jp) {
            $mi = $this->with_jpath($mi);
        } else {
            $fname = $field instanceof Si ? $field->name : $field;
            $mi = $mi->with_field($fname);
        }
        return $this->append_item($mi);
    }

    /** @param null|string|Si $field
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        if ($this->_jp) {
            $mi = $this->with_jpath(new MessageItem(null, $msg ?? "", $status));
        } else {
            $fname = $field instanceof Si ? $field->name : $field;
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
            if ($mi->field
                && ($si = $this->conf->si($mi->field))) {
                $loc = $si->title_html($this);
                if ($loc && $si->hashid !== false) {
                    $loc = Ht::link($loc, $si->sv_hoturl($this));
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
            $msgs[] = new MessageItem("", "Your changes were not saved. Please fix these errors and try again.", MessageSet::PLAIN);
        }
        foreach ($this->decorated_message_list() as $mi) {
            $msgs[] = $mi;
        }
        $this->conf->feedback_msg($msgs);
    }

    /** @return SettingParser */
    private function si_parser(Si $si) {
        return $this->cs()->callable($si->parser_class);
    }

    /** @param string $name
     * @return Si */
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

    /** @param string|Si $id
     * @return bool */
    function editable($id) {
        $si = is_string($id) ? $this->conf->si($id) : $id;
        if (!$si) {
            return false;
        } else {
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
    }

    /** @param string|Si $id
     * @return mixed */
    function oldv($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        if (array_key_exists($si->name, $this->_explicit_oldv)
            || ($si->parser_class && $this->si_find_oldv($si))) {
            $val = $this->_explicit_oldv[$si->name];
        } else if ($si->storage_type & Si::SI_OPT) {
            $val = $this->conf->opt(substr($si->storage_name(), 4)) ?? $si->default_value;
            if (($si->storage_type & Si::SI_VALUE) && is_bool($val)) {
                $val = (int) $val;
            }
        } else if ($si->storage_type & Si::SI_DATA) {
            $val = $this->conf->setting_data($si->storage_name()) ?? $si->default_value;
        } else if ($si->storage_type & Si::SI_VALUE) {
            $val = $this->conf->setting($si->storage_name()) ?? $si->default_value;
        } else if (($si->storage_type & Si::SI_MEMBER)
                   && ($obj = $this->objectv($si->part0 . $si->part1))) {
            $val = $obj->{$si->storage_name()};
        } else {
            error_log("setting $si->name: don't know how to get value");
            $val = $si->default_value;
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
        $this->si_parser($si)->set_oldv($this, $si);
        return array_key_exists($si->name, $this->_explicit_oldv);
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

    /** @param string $name */
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
        return $si->base_unparse_reqv($this->oldv($si));
    }


    /** @param String|Si $id
     * @return mixed */
    function vjson($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        if ($si->type === "objectlist") {
            $a = [];
            foreach ($this->enumerate("{$si->name}__") as $ctr) {
                $a[] = $this->vjson("{$si->name}__{$ctr}");
            }
            return $a;
        }
        if ($this->_use_req) {
            $name = is_string($id) ? $id : $id->name;
            if (isset($this->req[$name])) {
                return $si->base_unparse_jsonv($this->req[$name]);
            }
        }
        return $si->base_unparse_jsonv($this->oldv($si));
    }


    /** @param string|Si $id
     * @return ?object */
    private function objectv($id) {
        $name = is_string($id) ? $id : $id->name;
        if (!array_key_exists($name, $this->_explicit_oldv)) {
            $si = is_string($id) ? $this->si($id) : $id;
            if ($si && $si->parser_class) {
                $si->part0 !== null && $this->ensure_enumeration($si->part0);
                $this->si_parser($si)->set_oldv($this, $si);
            }
        }
        $v = $this->_explicit_oldv[$name] ?? null;
        return is_object($v) ? $v : null;
    }

    /** @param string $pfx */
    private function ensure_enumeration($pfx) {
        if (!isset($this->_ensure_enumerations[$pfx])) {
            $this->_ensure_enumerations[$pfx] = true;
            if (str_ends_with($pfx, "__")
                && ($si = $this->conf->si("{$pfx}1"))
                && $si->parser_class) {
                $this->si_parser($si)->prepare_enumeration($this, $si);
            } else if (($xpfx = preg_replace('/__\d+\z/', '__', $pfx)) !== $pfx) {
                $this->ensure_enumeration($xpfx);
            }
        }
    }

    /** @param string $pfx
     * @param array $map */
    function map_enumeration($pfx, $map) {
        assert(str_ends_with($pfx, "__"));
        $ctr = 1;
        if ($this->_use_req) {
            $delete_rest = ($this->reqstr("{$pfx}reset") ?? "") !== "";
            $used = $names = $namectrs = [];
            while (($x = $this->reqstr("{$pfx}{$ctr}__id")) !== null) {
                if ($x !== "") {
                    $used[$x] = true;
                } else if (($n = $this->reqstr("{$pfx}{$ctr}__name") ?? "") !== "") {
                    $names[] = $n;
                    $namectrs[] = $ctr;
                }
                ++$ctr;
            }
            if (!empty($names)) {
                $mnames = $mids = [];
                foreach ($map as $id => $obj) {
                    if (!isset($used[$id])
                        && is_object($obj)
                        && isset($obj->name)
                        && is_string($obj->name)) {
                        $mnames[] = $obj->name;
                        $mids[] = $id;
                    }
                }
                foreach ($this->unambiguous_renumbering($names, $mnames) as $idx => $midx) {
                    if ($midx >= 0) {
                        $this->req["{$pfx}{$namectrs[$idx]}__id"] = $mids[$midx];
                        $used[$mids[$midx]] = true;
                    }
                }
            }
            foreach ($map as $id => $obj) {
                if (!isset($used[$id])) {
                    $this->set_req("{$pfx}{$ctr}__id", (string) $id);
                    if ($delete_rest) {
                        $this->set_req("{$pfx}{$ctr}__delete", "1");
                    }
                    ++$ctr;
                }
            }
        } else {
            foreach ($map as $id => $obj) {
                $this->set_oldv("{$pfx}{$ctr}__id", (string) $id);
                $this->set_req("{$pfx}{$ctr}__id", (string) $id);
                ++$ctr;
            }
        }
        unset($this->req["{$pfx}{$ctr}__id"]);
    }

    /** @param string $pfx
     * @return list<int> */
    function enumerate($pfx) {
        assert(str_ends_with($pfx, "__"));
        $this->ensure_enumeration($pfx);
        $ctrs = [];
        for ($ctr = 1; isset($this->req["{$pfx}{$ctr}__id"]); ++$ctr) {
            $ctrs[] = $ctr;
        }
        if ($this->conf->si("{$pfx}1__order")) {
            usort($ctrs, function ($a, $b) use ($pfx) {
                $ao = $this->vstr("{$pfx}{$a}__order");
                $an = is_numeric($ao);
                $bo = $this->vstr("{$pfx}{$b}__order");
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

    /** @template T
     * @param string $pfx
     * @param array<T> $map
     * @return ?T */
    function unmap_enumeration_member($pfx, $map) {
        $this->ensure_enumeration($pfx);
        $x = $this->reqstr("{$pfx}__id");
        if ($x !== null && $x !== "" && $x !== "\$" && $x !== "new") {
            return $map[$x] ?? null;
        } else {
            return null;
        }
    }

    /** @param string $pfx
     * @param int|string $needle
     * @return ?int */
    function search_enumeration_id($pfx, $needle) {
        $this->ensure_enumeration($pfx);
        for ($ctr = 1; isset($this->req["{$pfx}{$ctr}__id"]); ++$ctr) {
            if ((string) $needle === (string) $this->req["{$pfx}{$ctr}__id"]) {
                return $ctr;
            }
        }
        return null;
    }

    /** @param string $pfx
     * @param int|string $ctr
     * @param string $sfx
     * @param string $description */
    function error_if_duplicate_member($pfx, $ctr, $sfx, $description) {
        assert(is_int($ctr) || (is_string($ctr) && ctype_digit($ctr)));
        $ctr = (int) $ctr;
        if ($this->reqstr("{$pfx}{$ctr}__delete")) {
            return;
        }
        $oim = $this->swap_ignore_messages(true);
        $collator = $this->conf->collator();
        $v0 = $this->base_parse_req("{$pfx}{$ctr}{$sfx}");
        $badctr = null;
        for ($ctr1 = $ctr + 1; isset($this->req["{$pfx}{$ctr1}__id"]); ++$ctr1) {
            if (!$this->reqstr("{$pfx}{$ctr1}__delete")
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
    function has_savedv($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        return array_key_exists($si->storage_name(), $this->_savedv);
    }

    /** @param string|Si $id
     * @return mixed */
    function savedv($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        assert($si->storage_type !== Si::SI_NONE);
        return $this->si_savedv($si->storage_name(), $si);
    }

    /** @param string $storage_name
     * @param Si $si
     * @return mixed */
    private function si_savedv($storage_name, $si) {
        assert(($si->storage_type & Si::SI_MEMBER) === 0);
        if (array_key_exists($storage_name, $this->_savedv)) {
            $v = $this->_savedv[$storage_name];
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


    /** @param string|Si $id */
    function newv($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        $s = $si->storage_name();
        if (array_key_exists($s, $this->_savedv)) {
            return $this->si_savedv($s, $si);
        } else {
            return $this->oldv($si);
        }
    }


    /** @param string|Si $id
     * @return bool */
    function has_interest($id) {
        if (!$this->canonical_page) {
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

    /** @param ?string $c1
     * @param ?string $c2
     * @return ?string */
    static function join_class($c1, $c2) {
        if ($c1 === null || $c1 === "") {
            return $c2;
        } else if ($c2 === null || $c2 === "") {
            return $c1;
        } else {
            return "{$c1} {$c2}";
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
                    $label_js["class"] = self::join_class($sc, $label_js["class"] ?? null);
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
        if ($si && !isset($js["disabled"]) && !isset($js["readonly"])) {
            if ($si->disabled) {
                $x["disabled"] = true;
            } else if (!$this->editable($si)) {
                if (in_array($si->type, ["checkbox", "radio", "select", "cdate", "tagselect"], true)) {
                    $x["disabled"] = true;
                } else {
                    $x["readonly"] = true;
                }
            }
        }
        if ($this->_use_req
            && !isset($js["data-default-value"])
            && !isset($js["data-default-checked"])) {
            if ($si && $this->has_interest($si)) {
                $x["data-default-value"] = $si->base_unparse_reqv($this->oldv($si));
            } else if (isset($this->_explicit_oldv[$name])) {
                $x["data-default-value"] = $this->_explicit_oldv[$name];
            }
        }
        foreach ($js ?? [] as $k => $v) {
            if (strlen($k) < 10
                || !preg_match('/\A(?:group_|hint_|control_|label_|fold_|horizontal\z|no_control_class\z)/', $k))
                $x[$k] = $v;
        }
        if ($this->has_problem_at($name)) {
            $x["class"] = $this->control_class($name, $x["class"] ?? "");
        }
        if (isset($js["fold_values"])) {
            $x["class"] = self::join_class($x["class"] ?? "", "uich js-foldup");
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
            $xjs["class"] = $this->control_class($si->name, $xjs["class"]);
        }
        if (isset($js["group_class"])) {
            $xjs["class"] = self::join_class($xjs["class"], $js["group_class"]);
        }
        if (isset($js["fold_values"]) && !empty($js["fold_values"])) {
            $fv = $js["fold_values"];
            assert(is_array($fv));
            $fold = "fold" . (in_array($this->vstr($si->name), $fv) ? "o" : "c");
            $xjs["class"] = self::join_class($xjs["class"], "has-fold {$fold}");
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
     * @param string $hint
     * @return void */
    function print_checkbox($name, $text, $js = null, $hint = "") {
        $js = $js ?? [];
        $this->print_group_open($name, "checki", $js + ["no_control_class" => true]);
        echo '<span class="checkc">';
        $this->print_checkbox_only($name, $js);
        echo '</span>', $this->label($name, $text, ["for" => $name, "class" => $js["label_class"] ?? null]);
        $this->print_feedback_at($name);
        if ($hint) {
            echo '<div class="', self::join_class("settings-ap f-hx", $js["hint_class"] ?? null), '">', $hint, '</div>';
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
        $v = $this->vstr($si);
        $js = $this->sjs($si, $js ?? []);
        if ($si->size && !isset($js["size"])) {
            $js["size"] = $si->size;
        }
        if ($si->placeholder !== null && !isset($js["placeholder"])) {
            $js["placeholder"] = $si->placeholder;
        }
        if ($si->autogrow) {
            $js["class"] = ltrim(($js["class"] ?? "") . " need-autogrow");
        }
        if ($si->default_value !== null
            && isset($js["placeholder"])
            && $v === (string) $si->default_value) {
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

    /** @param string $name
     * @param string $description
     * @param string $control
     * @param ?array<string,mixed> $js
     * @param string $hint */
    function print_control_group($name, $description, $control,
                                 $js = null, $hint = "") {
        $si = $this->si($name);
        $horizontal = !!($js["horizontal"] ?? false);
        $this->print_group_open($name, $horizontal ? "entryi" : "f-i", $js);

        if ($description === null) {
            $description = $si->title_html($this);
        }
        echo $this->label($name, $description, ["class" => $js["label_class"] ?? null, "no_control_class" => true]);
        if ($horizontal) {
            echo '<div class="entry">';
        }
        $this->print_feedback_at($name);
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
        if (!($js["group_open"] ?? null)) {
            echo $horizontal ? "</div></div>\n" : "</div>\n";
        }
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @param string $hint
     * @return void */
    function print_entry_group($name, $description, $js = null, $hint = "") {
        $this->print_control_group($name, $description,
            $this->entry($name, $js),
            $js, $hint);
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
     * @param ?array<string,mixed> $js
     * @param string $hint */
    function print_select_group($name, $description, $values, $js = null, $hint = "") {
        $this->print_control_group($name, $description,
            $this->select($name, $values, $js),
            $js, $hint);
    }

    /** @param string $name
     * @param ?array<string,mixed> $js
     * @return string */
    function textarea($name, $js = null) {
        $si = $this->si($name);
        $v = $this->vstr($si);
        $js = $this->sjs($si, $js ?? []);
        if ($si->placeholder !== null && !isset($js["placeholder"])) {
            $js["placeholder"] = $si->placeholder;
        }
        if ($si->autogrow ?? true) {
            $js["class"] = self::join_class($js["class"] ?? "", "need-autogrow");
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
     * @param string $hint
     * @return void */
    function print_textarea_group($name, $description, $js = null, $hint = "") {
        $this->print_control_group($name, $description,
            $this->textarea($name, $js),
            $js, $hint);
    }

    /** @param string $name
     * @param string $description
     * @param string $hint
     * @param string $xclass */
    private function print_message_base($name, $description, $hint, $xclass) {
        $si = $this->si($name);
        $current = $this->vstr($si);
        $description = '<a class="ui q js-foldup" href="">'
            . expander(null, 0) . $description . '</a>';
        echo '<div class="f-i has-fold fold', ($current == $si->default_value($this) ? "c" : "o"), '">',
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
                $this->error_at($name0, "<5>Must come before " . $this->setting_link($si1->title_html($this), $si1));
                $this->error_at($name1);
                return false;
            }
        }
        return true;
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


    /** @param string $html
     * @param string|Si $id
     * @return string */
    function setting_link($html, $id, $js = null) {
        $si = is_string($id) ? $this->si($id) : $id;
        return Ht::link($html, $si->sv_hoturl($this), $js);
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


    /** @param string|Si $id
     * @return void */
    function save($id, $value) {
        $si = is_string($id) ? $this->si($id) : $id;
        if ($value === null) {
            error_log("setting {$si->name}: setting value to null: " . debug_string_backtrace());
        }
        $member = ($si->storage_type & Si::SI_MEMBER) !== 0;
        if (!$si || $si->storage_type === Si::SI_NONE) {
            error_log("setting {$si->name}: no setting or cannot save value");
            return;
        }
        if ($si->storage_type & Si::SI_NEGATE) {
            $value = !$value;
        }
        if ($value !== null
            && !$member
            && !($si->storage_type & Si::SI_DATA ? is_string($value) : is_int($value) || is_bool($value))) {
            error_log(caller_landmark() . ": setting {$si->name}: invalid value " . var_export($value, true));
            return;
        }
        if ($si->value_nullable($value, $this)) {
            $value = null;
        }
        $value1 = $value;
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $s = $si->storage_name();
        if ($member) {
            $this->cur_object->{$s} = $value1;
        } else if ($si->storage_type & Si::SI_SLICE) {
            if (!isset($this->_savedv[$s])) {
                if (!array_key_exists($s, $this->_savedv)) {
                    $this->_savedv[$s] = [$this->conf->setting($s) ?? 0, $this->conf->setting_data($s)];
                } else {
                    $this->_savedv[$s] = [0, null];
                }
            }
            if ($si->storage_type & Si::SI_DATA) {
                $this->_savedv[$s][1] = $value;
            } else {
                $this->_savedv[$s][0] = $value ?? 0;
            }
            if ($this->_savedv[$s][0] === 0 && $this->_savedv[$s][1] === null) {
                $this->_savedv[$s] = null;
            }
        } else if ($value === null) {
            $this->_savedv[$s] = null;
        } else if ($si->storage_type & Si::SI_DATA) {
            $this->_savedv[$s] = [1, $value];
        } else {
            $this->_savedv[$s] = [$value, null];
        }

        if ($si->ifnonempty) {
            $this->save($si->ifnonempty, isset($this->_savedv[$s]));
        }
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
     * @return void */
    function unsave($id) {
        $si = is_string($id) ? $this->si($id) : $id;
        assert($si->storage_type !== Si::SI_NONE
               && !($si->storage_type & (Si::SI_MEMBER | Si::SI_SLICE)));
        unset($this->_savedv[$si->storage_name()]);
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
            && !$si->disabled
            && $this->editable($si)
            && (!$si->parser_class
                || $this->si_parser($si)->apply_req($this, $si) === false)
            && $si->storage_type !== Si::SI_NONE
            && ($value = $si->parse_reqv($this->reqstr($si->name), $this)) !== null) {
            $this->save($si, $value);
        }
    }

    /** @return $this */
    function parse() {
        assert(!$this->_req_parsed);
        $this->_req_parsed = true;

        // find requested settings
        $siset = $this->conf->si_set();
        $this->_req_si = [];
        foreach ($this->req as $k => $v) {
            if (!str_starts_with($k, "has_")
                && ($si = $siset->get($k))
                && $si->name === $k) {   // don’t count aliases
                $this->_req_si[] = $si;
            }
        }
        usort($this->_req_si, "Si::parse_order_compare");

        // parse and validate settings
        foreach ($this->_req_si as $si) {
            if (($si->storage_type & Si::SI_MEMBER) === 0)
                $this->apply_req($si);
        }

        return $this;
    }

    /** @param string $pfx
     * @param bool $descendents
     * @return list<Si> */
    function si_req_members($pfx, $descendents = false) {
        assert($this->_req_parsed && str_ends_with($pfx, "__"));
        $sis = [];
        foreach ($this->_req_si as $si) {
            if (str_starts_with($si->name, $pfx)
                && ($descendents || strlen($si->part0) + strlen($si->part1) === strlen($pfx) - 2))
                $sis[] = $si;
        }
        return $sis;
    }

    /** @param string $oname
     * @return object */
    function parse_members($oname) {
        $object = $this->objectv($oname);
        assert($object && $this->_req_parsed && !str_ends_with($oname, "__"));
        $object = clone $object;
        $old_object = $this->cur_object;
        $this->cur_object = $object;
        // skip member parsing if object is deleted (don't want errors)
        if (!$this->reqstr("{$oname}__delete")) {
            foreach ($this->si_req_members("{$oname}__") as $si) {
                if (($si->storage_type & Si::SI_MEMBER) !== 0)
                    $this->apply_req($si);
            }
        }
        $this->cur_object = $old_object;
        return $object;
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

    /** @return bool */
    function execute() {
        if (!$this->_req_parsed) {
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
            $this->conf->qe_raw("lock tables $tables");
            $this->conf->save_logs(true);

            // load db settings, pre-crosscheck
            $dbsettings = [];
            $result = $this->conf->qe("select name, value, data from Settings");
            while (($row = $result->fetch_row())) {
                $dbsettings[$row[0]] = $row;
            }
            Dbl::free($result);

            // apply settings
            foreach ($this->_saved_si as $si) {
                $this->si_parser($si)->store_value($this, $si);
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
                    : isset($dbsettings[$n]) && (int) $dbsettings[$n][1] === $v[0] && $dbsettings[$n][2] === $v[1]) {
                    continue;
                }
                $this->_diffs[$n] = true;
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
            $this->conf->save_logs(false);
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

    /** @return list<string> */
    function updated_fields() {
        return array_keys($this->_diffs);
    }
}
