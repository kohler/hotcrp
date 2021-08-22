<?php
// paperoption.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class PaperValue implements JsonSerializable {
    /** @var PaperInfo
     * @readonly */
    public $prow;
    /** @var int
     * @readonly */
    public $id;
    /** @var PaperOption
     * @readonly */
    public $option;
    /** @var ?int */
    public $value;
    /** @var ?array<string,mixed> */
    public $anno;
    /** @var list<int> */
    private $_values = [];
    /** @var list<?string> */
    private $_data = [];
    /** @var ?DocumentInfoSet */
    private $_docset;
    /** @var ?MessageSet */
    private $_ms;

    /** @param PaperInfo $prow */
    function __construct($prow, PaperOption $o) { // XXX should be private
        $this->prow = $prow;
        $this->id = $o->id;
        $this->option = $o;
    }
    /** @param PaperInfo $prow
     * @param ?int $value
     * @param ?string $data
     * @return PaperValue */
    static function make($prow, PaperOption $o, $value = null, $data = null) {
        $ov = new PaperValue($prow, $o);
        if ($value !== null) {
            $ov->set_value_data([$value], [$data]);
        }
        return $ov;
    }
    /** @param PaperInfo $prow
     * @param list<int> $values
     * @param list<?string> $datas
     * @return PaperValue */
    static function make_multi($prow, PaperOption $o, $values, $datas) {
        $ov = new PaperValue($prow, $o);
        $ov->set_value_data($values, $datas);
        return $ov;
    }
    /** @param PaperInfo $prow
     * @param string $error_html
     * @return PaperValue */
    static function make_estop($prow, PaperOption $o, $error_html) {
        $ov = new PaperValue($prow, $o);
        $ov->estop($error_html);
        return $ov;
    }
    /** @param PaperInfo $prow
     * @return PaperValue */
    static function make_force($prow, PaperOption $o) {
        $ov = new PaperValue($prow, $o);
        $o->value_force($ov);
        return $ov;
    }
    function load_value_data() {
        $ovd = $this->prow->option_value_data($this->id);
        $this->set_value_data($ovd[0], $ovd[1]);
    }
    /** @param list<int> $values
     * @param list<?string> $datas */
    function set_value_data($values, $datas) {
        if ($this->_values != $values) {
            $this->_values = $values;
            $this->_docset = null;
        }
        $this->value = $this->_values[0] ?? null;
        $this->_data = $datas;
    }
    /** @return ?string */
    function data() {
        if ($this->_data === null) {
            $this->load_value_data();
        }
        if ($this->value !== null) {
            return $this->_data[0] ?? null;
        } else {
            return null;
        }
    }
    /** @return int */
    function value_count() {
        return count($this->_values);
    }
    /** @return list<int> */
    function value_list() {
        return $this->_values;
    }
    /** @return list<?string> */
    function data_list() {
        if ($this->_data === null) {
            $this->load_value_data();
        }
        return $this->_data;
    }
    /** @param int $index
     * @return ?string */
    function data_by_index($index) {
        return ($this->data_list())[$index] ?? null;
    }
    /** @return DocumentInfoSet */
    function document_set() {
        assert($this->prow || empty($this->_values));
        assert($this->option->has_document());
        if ($this->_docset === null) {
            // NB that $this->_docset might be invalidated by value_dids
            $docset = new DocumentInfoSet;
            foreach ($this->option->value_dids($this) as $did) {
                if (($d = $this->prow->document($this->id, $did))) {
                    $docset->add($d);
                }
            }
            assert(!$this->_docset);
            $this->_docset = $docset;
        }
        return $this->_docset;
    }
    /** @return list<DocumentInfo> */
    function documents() {
        return $this->document_set()->as_list();
    }
    /** @param int $index
     * @return ?DocumentInfo */
    function document($index) {
        return $this->document_set()->document_by_index($index);
    }
    /** @param int $index
     * @return string|false */
    function document_content($index) {
        $doc = $this->document($index);
        return $doc ? $doc->content() : false;
    }
    /** @param string $name
     * @return ?DocumentInfo */
    function attachment($name) {
        return $this->option->attachment($this, $name);
    }
    function invalidate() {
        $this->prow->invalidate_options(true);
        $this->load_value_data();
    }
    function call($method, ...$args) {
        return $this->option->$method($this, ...$args);
    }

    /** @param string $name
     * @return bool */
    function has_anno($name) {
        return isset($this->anno[$name]);
    }
    /** @param string $name
     * @return mixed */
    function anno($name) {
        return $this->anno[$name] ?? null;
    }
    /** @param string $name
     * @param mixed $value */
    function set_anno($name, $value) {
        $this->anno[$name] = $value;
    }
    /** @param string $name
     * @param mixed $value */
    function push_anno($name, $value) {
        $this->anno = $this->anno ?? [];
        $this->anno[$name][] = $value;
    }

    /** @return MessageSet */
    function message_set() {
        $this->_ms = $this->_ms ?? new MessageSet;
        return $this->_ms;
    }
    /** @param MessageSet $ms */
    function copy_messages_to($ms) {
        if ($this->_ms) {
            $ms->add_set($this->_ms);
        }
    }
    /** @param string $field
     * @param ?string $msg
     * @param -4|-3|-2|-1|0|1|2|3 $status */
    function msg_at($field, $msg, $status) {
        $this->message_set()->msg_at($field, $msg, $status);
    }
    /** @param ?string $msg
     * @param -4|-3|-2|-1|0|1|2|3 $status */
    function msg($msg, $status) {
        $this->message_set()->msg_at($this->option->field_key(), $msg, $status);
    }
    /** @param ?string $msg */
    function estop($msg) {
        $this->msg($msg, MessageSet::ESTOP);
    }
    /** @param ?string $msg */
    function error($msg) {
        $this->msg($msg, MessageSet::ERROR);
    }
    /** @param ?string $msg */
    function warning($msg) {
        $this->msg($msg, MessageSet::WARNING);
    }
    /** @return int */
    function problem_status() {
        return $this->_ms ? $this->_ms->problem_status() : 0;
    }
    /** @return bool */
    function has_error() {
        return $this->_ms && $this->_ms->has_error();
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }
    function jsonSerialize() {
        if ($this->_data === null
            || $this->_data === []
            || (count($this->_data) === 1 && $this->_data[0] === null)) {
            $x = $this->value;
        } else {
            $x = [];
            for ($i = 0; $i !== count($this->_values); ++$i) {
                $x[] = [$this->_values[$i], $this->_data[$i]];
            }
        }
        return [$this->option->json_key() => $x];
    }
}

class PaperOptionList implements IteratorAggregate {
    /** @var Conf */
    private $conf;
    /** @var array<int,object> */
    private $_jmap;
    /** @var array<int,?PaperOption> */
    private $_omap = [];
    /** @var array<int,object> */
    private $_ijmap;
    /** @var array<int,?PaperOption> */
    private $_imap = [];
    /** @var ?array<int,PaperOption> */
    private $_olist;
    /** @var ?array<int,PaperOption> */
    private $_olist_nonfinal;
    /** @var AbbreviationMatcher<PaperOption> */
    private $_nonpaper_am;
    private $_accumulator;

    const DTYPE_SUBMISSION_JSON = '{"id":0,"name":"paper","json_key":"submission","form_position":1001,"type":"document"}';
    const DTYPE_FINAL_JSON = '{"id":-1,"name":"final","json_key":"final","form_position":1002,"type":"document"}';

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function _add_json($oj, $k, $landmark) {
        if (!isset($oj->id) && $k === 0) {
            throw new Exception("This conference could not be upgraded from an old database schema. A system administrator must fix this problem.");
        }
        if (is_string($oj->id) && is_numeric($oj->id)) { // XXX backwards compat
            $oj->id = intval($oj->id);
        }
        if (is_int($oj->id) && $oj->id > 0) {
            if ($this->conf->xt_allowed($oj)
                && (!isset($this->_jmap[$oj->id])
                    || Conf::xt_priority_compare($oj, $this->_jmap[$oj->id]) <= 0)) {
                $this->_jmap[$oj->id] = $oj;
            }
            return true;
        } else {
            return false;
        }
    }

    /** @param array<int,object> $jmap */
    function set_option_json_map($jmap) {
        $this->_jmap = $jmap;
        $this->_omap = [];
        $this->_olist = $this->_olist_nonfinal = $this->_nonpaper_am = null;
    }

    /** @return array<int,object> */
    private function option_json_map() {
        if ($this->_jmap === null) {
            $this->_jmap = [];
            if (($olist = $this->conf->setting_json("options"))) {
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            if (($olist = $this->conf->opt("fixedOptions"))) {
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            $this->_jmap = array_filter($this->_jmap, "Conf::xt_enabled");
        }
        return $this->_jmap;
    }

    private function add_abbrev_matcher(AbbreviationMatcher $am, $id, $oj) {
        $cb = [$this, "option_by_id"];
        $am->add_keyword_lazy("opt{$id}", $cb, [$id], Conf::MFLAG_OPTION);
        if ($oj->name ?? null) {
            $am->add_phrase_lazy($oj->name, $cb, [$id], Conf::MFLAG_OPTION);
        }
        $oj->search_keyword = $oj->search_keyword ?? $oj->json_key ?? null;
        if ($oj->search_keyword) {
            $am->add_keyword_lazy($oj->search_keyword, $cb, [$id], Conf::MFLAG_OPTION);
        }
        if (($oj->json_key ?? null)
            && $oj->json_key !== $oj->search_keyword
            && (($oj->name ?? null)
                || strcasecmp(str_replace("_", " ", $oj->json_key), $oj->name) !== 0)) {
            $am->add_keyword_lazy($oj->json_key, $cb, [$id], Conf::MFLAG_OPTION);
        }
    }

    function populate_abbrev_matcher(AbbreviationMatcher $am) {
        $cb = [$this, "option_by_id"];
        $am->add_keyword_lazy("paper", $cb, [DTYPE_SUBMISSION], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("submission", $cb, [DTYPE_SUBMISSION], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("final", $cb, [DTYPE_FINAL], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("title", $cb, [PaperOption::TITLEID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("authors", $cb, [PaperOption::AUTHORSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("nonblind", $cb, [PaperOption::ANONYMITYID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("contacts", $cb, [PaperOption::CONTACTSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("abstract", $cb, [PaperOption::ABSTRACTID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("topics", $cb, [PaperOption::TOPICSID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("pc_conflicts", $cb, [PaperOption::PCCONFID], Conf::MFLAG_OPTION);
        $am->add_keyword_lazy("collaborators", $cb, [PaperOption::COLLABORATORSID], Conf::MFLAG_OPTION);
        foreach ($this->option_json_map() as $id => $oj) {
            if (($oj->nonpaper ?? false) !== true) {
                $this->add_abbrev_matcher($am, $id, $oj);
            }
        }
    }

    function assign_search_keywords($nonpaper, AbbreviationMatcher $am) {
        $cb = [$this, "option_by_id"];
        foreach ($this->option_json_map() as $id => $oj) {
            if (!isset($oj->search_keyword)
                && (($oj->nonpaper ?? false) === true) === $nonpaper) {
                if ($oj->name ?? null) {
                    $e = AbbreviationEntry::make_lazy($oj->name, $cb, [$id], Conf::MFLAG_OPTION);
                    $s = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL, Conf::MFLAG_OPTION) ?? false;
                } else {
                    $s = false;
                }
                $oj->search_keyword = $s;
                if (($o = $this->_omap[$id] ?? null)) {
                    $o->_search_keyword = $s;
                }
            }
        }
    }

    function _add_intrinsic_json($oj, $k, $landmark) {
        assert(is_int($oj->id) && $oj->id <= 0);
        $this->_accumulator[(string) $oj->id][] = $oj;
        return true;
    }

    /** @return array<int,object> */
    private function intrinsic_json_map() {
        if ($this->_ijmap === null) {
            $this->_accumulator = $this->_ijmap = [];
            foreach (["etc/intrinsicoptions.json", self::DTYPE_SUBMISSION_JSON, self::DTYPE_FINAL_JSON, $this->conf->opt("intrinsicOptions"), $this->conf->setting_json("ioptions")] as $x) {
                if ($x)
                    expand_json_includes_callback($x, [$this, "_add_intrinsic_json"]);
            }
            /** @phan-suppress-next-line PhanEmptyForeach */
            foreach ($this->_accumulator as $id => $x) {
                if (($ij = $this->conf->xt_search_name($this->_accumulator, $id, null)))
                    $this->_ijmap[$ij->id] = $ij;
            }
            $this->_accumulator = null;
        }
        return $this->_ijmap;
    }

    /** @param int $id */
    private function populate_intrinsic($id) {
        if ($id == DTYPE_SUBMISSION) {
            $this->_imap[$id] = new Document_PaperOption($this->conf, json_decode(self::DTYPE_SUBMISSION_JSON));
        } else if ($id == DTYPE_FINAL) {
            $this->_imap[$id] = new Document_PaperOption($this->conf, json_decode(self::DTYPE_FINAL_JSON));
        } else {
            $this->_imap[$id] = null;
            if (($oj = ($this->intrinsic_json_map())[$id] ?? null)
                && ($o = PaperOption::make($oj, $this->conf))) {
                $this->_imap[$id] = $o;
            }
        }
    }

    /** @param int $id
     * @return ?PaperOption */
    function option_by_id($id) {
        if ($id <= 0) {
            if (!array_key_exists($id, $this->_imap)) {
                $this->populate_intrinsic($id);
            }
            return $this->_imap[$id];
        } else {
            if (!array_key_exists($id, $this->_omap)) {
                $this->_omap[$id] = null;
                if (($oj = ($this->option_json_map())[$id] ?? null)
                    && Conf::xt_enabled($oj)
                    && $this->conf->xt_allowed($oj)) {
                    $this->_omap[$id] = PaperOption::make($oj, $this->conf);
                }
            }
            return $this->_omap[$id];
        }
    }

    /** @param int $id
     * @return PaperOption */
    function checked_option_by_id($id) {
        $o = $this->option_by_id($id);
        if (!$o) {
            throw new Exception("PaperOptionList::checked_option_by_id($id) failed");
        }
        return $o;
    }

    /** @param string $key
     * @return ?PaperOption */
    function option_by_field_key($key) {
        // Since this function is rarely used, don’t bother optimizing it.
        if (($colon = strpos($key, ":"))) {
            $key = substr($key, 0, $colon);
        }
        foreach ($this->unsorted_field_list() as $f) {
            if ($f->field_key() === $key)
                return $f;
        }
        return null;
    }

    /** @return array<int,PaperOption> */
    function normal() {
        if ($this->_olist === null) {
            $this->_olist = [];
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) !== true
                    && ($o = $this->option_by_id($id))) {
                    $this->_olist[$id] = $o;
                }
            }
            uasort($this->_olist, "PaperOption::compare");
        }
        return $this->_olist;
    }

    function getIterator() {
        $this->normal();
        return new ArrayIterator($this->_olist);
    }

    /** @return array<int,PaperOption> */
    function nonfinal() {
        if ($this->_olist_nonfinal === null) {
            $this->_olist_nonfinal = [];
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) !== true
                    && ($oj->final ?? false) !== true
                    && ($o = $this->option_by_id($id))) {
                    assert(!$o->nonpaper && !$o->final);
                    $this->_olist_nonfinal[$id] = $o;
                }
            }
            uasort($this->_olist_nonfinal, "PaperOption::compare");
        }
        return $this->_olist_nonfinal;
    }

    /** @return array<int,PaperOption> */
    function nonpaper() {
        $list = [];
        foreach ($this->option_json_map() as $id => $oj) {
            if (($oj->nonpaper ?? false) === true
                && ($o = $this->option_by_id($id)))
                $list[$id] = $o;
        }
        uasort($list, "PaperOption::compare");
        return $list;
    }

    /** @return array<int,PaperOption> */
    function universal() {
        $list = [];
        foreach ($this->option_json_map() as $id => $oj) {
            if (($o = $this->option_by_id($id)))
                $list[$id] = $o;
        }
        uasort($list, "PaperOption::compare");
        return $list;
    }

    private function _get_field($id, $oj, $nonfinal) {
        if (($oj->nonpaper ?? false) !== true
            && !($nonfinal && ($oj->final ?? false) === true)) {
            return $this->option_by_id($id);
        } else {
            return null;
        }
    }

    private function unsorted_field_list(PaperInfo $prow = null, $key = null) {
        $nonfinal = $prow && $prow->outcome <= 0;
        $olist = [];
        foreach ($this->intrinsic_json_map() as $id => $oj) {
            if ((!$key || ($oj->$key ?? null) !== false)
                && ($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[$id] = $o;
        }
        foreach ($this->option_json_map() as $id => $oj) {
            if ((!$key || ($oj->$key ?? null) !== false)
                && ($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[$id] = $o;
        }
        return $olist;
    }

    /** @return array<int,PaperOption> */
    function display_fields(PaperInfo $prow = null) {
        $olist = $this->unsorted_field_list($prow, "display_position");
        uasort($olist, "PaperOption::compare");
        return $olist;
    }

    /** @return array<int,PaperOption> */
    function form_fields(PaperInfo $prow = null) {
        $olist = $this->unsorted_field_list($prow, "form_position");
        uasort($olist, "PaperOption::form_compare");
        return $olist;
    }

    function invalidate_options() {
        if ($this->_jmap !== null || $this->_ijmap !== null) {
            $this->_jmap = $this->_ijmap = null;
            $this->_omap = $this->_imap = [];
            $this->_olist = $this->_olist_nonfinal = $this->_nonpaper_am = null;
        }
    }

    /** @param int $id */
    function invalidate_intrinsic_option($id) {
        unset($this->_imap[$id]);
    }

    /** @return bool */
    function has_universal() {
        return count($this->option_json_map()) !== 0;
    }

    /** @return array<int,PaperOption> */
    function find_all($name) {
        $iname = strtolower($name);
        if ($iname === (string) DTYPE_SUBMISSION
            || $iname === "paper"
            || $iname === "submission") {
            return [DTYPE_SUBMISSION => $this->option_by_id(DTYPE_SUBMISSION)];
        } else if ($iname === (string) DTYPE_FINAL
                   || $iname === "final") {
            return [DTYPE_FINAL => $this->option_by_id(DTYPE_FINAL)];
        } else if ($iname === "" || $iname === "none") {
            return [];
        } else if ($iname === "any") {
            return $this->normal();
        } else if (substr($iname, 0, 3) === "opt"
                   && ctype_digit(substr($iname, 3))) {
            $o = $this->option_by_id((int) substr($iname, 3));
            return $o ? [$o->id => $o] : [];
        } else {
            if (substr($iname, 0, 4) === "opt-") {
                $name = substr($name, 4);
            }
            $omap = [];
            foreach ($this->conf->find_all_fields($name, Conf::MFLAG_OPTION) as $o) {
                $omap[$o->id] = $o;
            }
            return $omap;
        }
    }

    /** @return ?PaperOption */
    function find($name) {
        $omap = $this->find_all($name);
        reset($omap);
        return count($omap) === 1 ? current($omap) : null;
    }

    /** @return AbbreviationMatcher<PaperOption> */
    function nonpaper_abbrev_matcher() {
        // Nonpaper options aren't stored in the main abbrevmatcher; put them
        // in their own.
        if (!$this->_nonpaper_am) {
            $this->_nonpaper_am = new AbbreviationMatcher;
            foreach ($this->option_json_map() as $id => $oj) {
                if (($oj->nonpaper ?? false) === true) {
                    $this->add_abbrev_matcher($this->_nonpaper_am, $id, $oj);
                }
            }
            $this->assign_search_keywords(true, $this->_nonpaper_am);
        }
        return $this->_nonpaper_am;
    }

    /** @return array<int,PaperOption> */
    function find_all_nonpaper($name) {
        $omap = [];
        foreach ($this->nonpaper_abbrev_matcher()->find_all($name) as $o) {
            $omap[$o->id] = $o;
        }
        return $omap;
    }

    /** @return ?PaperOption */
    function find_nonpaper($name) {
        $omap = $this->find_all_nonpaper($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }
}

class PaperOption implements JsonSerializable {
    const TITLEID = -1000;
    const AUTHORSID = -1001;
    const ANONYMITYID = -1002;
    const CONTACTSID = -1003;
    const ABSTRACTID = -1004;
    const TOPICSID = -1005;
    const PCCONFID = -1006;
    const COLLABORATORSID = -1007;
    const SUBMISSION_VERSION_ID = -1008;

    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $id;
    /** @var ?string
     * @readonly */
    public $name;
    /** @var string
     * @readonly */
    public $formid;
    private $_readable_formid;
    /** @var ?string
     * @readonly */
    private $title;
    /** @var string
     * @readonly */
    public $type; // checkbox, selector, radio, numeric, text,
                  // pdf, slides, video, attachments, ...
    private $_json_key;
    /** @var null|string|false */
    public $_search_keyword;
    public $description;
    public $description_format;
    public $position;
    /** @var bool
     * @readonly */
    public $nonpaper;
    /** @var bool
     * @readonly */
    public $required;
    /** @var bool
     * @readonly */
    public $final;
    /** @var bool
     * @readonly */
    public $configurable;
    /** @var bool
     * @readonly */
    public $include_empty;
    /** @var int
     * @readonly */
    private $_visibility;
    /** @var int
     * @readonly */
    private $display;
    public $display_expand;
    public $display_group;
    private $form_position;
    private $display_position;
    /** @var null|string|false */
    public $list_class;
    private $exists_if;
    /** @var ?PaperSearch */
    private $_exists_search;
    private $editable_if;
    /** @var ?PaperSearch */
    private $_editable_search;
    public $max_size;

    const VIS_SUB = 0;         // visible if paper is visible (= all)
    const VIS_AUTHOR = 1;      // visible if authors are visible
    const VIS_CONFLICT = 2;    // visible if conflicts are visible
    const VIS_REVIEW = 3;      // visible after submitted review or reviews visible
    const VIS_ADMIN = 4;       // visible only to admins
    static private $visibility_map = ["all", "nonblind", "conflict", "review", "admin"];

    const DISP_TOPICS = 0;
    const DISP_PROMINENT = 1;
    const DISP_SUBMISSION = 2;
    const DISP_DEFAULT = 3;
    const DISP_NONE = -1;
    static private $display_map = [
        "default" => self::DISP_DEFAULT, "submission" => self::DISP_SUBMISSION,
        "topics" => self::DISP_TOPICS, "prominent" => self::DISP_PROMINENT,
        "none" => self::DISP_NONE
    ];
    static private $display_rmap = null;

    static private $callback_map = [
        "separator" => "+Separator_PaperOption",
        "checkbox" => "+Checkbox_PaperOption",
        "radio" => "+Selector_PaperOption",
        "selector" => "+Selector_PaperOption",
        "numeric" => "+Numeric_PaperOption",
        "text" => "+Text_PaperOption",
        "pdf" => "+Document_PaperOption",
        "slides" => "+Document_PaperOption",
        "video" => "+Document_PaperOption",
        "document" => "+Document_PaperOption",
        "attachments" => "+Attachments_PaperOption"
    ];

    /** @param stdClass $args */
    function __construct(Conf $conf, $args) {
        assert(is_object($args));
        assert($args->id > 0 || isset($args->json_key));
        if (!is_object($args)) {
            $args = (object) $args;
        }
        $this->conf = $conf;
        $this->id = (int) $args->id;
        $this->name = $args->name ?? null;
        $this->title = $args->title ?? null;
        if (!$this->title && $this->id > 0) {
            $this->title = $this->name;
        }
        $this->type = $args->type ?? null;

        $this->_json_key = $this->_readable_formid = $args->json_key ?? null;
        $this->_search_keyword = $args->search_keyword ?? $this->_json_key;
        $this->formid = $this->id > 0 ? "opt{$this->id}" : $this->_json_key;

        $this->description = $args->description ?? null;
        $this->description_format = $args->description_format ?? null;
        $this->nonpaper = ($args->nonpaper ?? false) === true;
        $this->required = !!($args->required ?? false);
        $this->final = ($args->final ?? false) === true;
        $this->configurable = ($args->configurable ?? null) !== false;
        $this->include_empty = ($args->include_empty ?? false) === true;

        $vis = $args->visibility ?? $args->view_type ?? null;
        if ($vis === null || $vis === "all" || $vis === "rev") {
            $this->_visibility = self::VIS_SUB;
        } else if ($vis === "nonblind") {
            $this->_visibility = self::VIS_AUTHOR;
        } else if ($vis === "conflict") {
            $this->_visibility = self::VIS_CONFLICT;
        } else if ($vis === "review") {
            $this->_visibility = self::VIS_REVIEW;
        } else if ($vis === "admin") {
            $this->_visibility = self::VIS_ADMIN;
        } else {
            $this->_visibility = self::VIS_SUB;
        }

        $disp = $args->display ?? null;
        if ($args->near_submission ?? false) {
            $disp = "submission";
        } else if ($args->highlight ?? false) {
            $disp = "prominent";
        } else if ($disp === null) {
            $disp = "topics";
        } else if ($disp === false) {
            $disp = "none";
        }
        $this->display = self::$display_map[$disp] ?? self::DISP_DEFAULT;
        if ($this->display === self::DISP_DEFAULT) {
            $this->display = $this->has_document() ? self::DISP_PROMINENT : self::DISP_TOPICS;
        }

        $p = $args->position ?? null;
        if ((is_int($p) || is_float($p))
            && ($this->id <= 0 || $p > 0)) {
            $this->position = $p;
        } else {
            $this->position = 499;
        }

        $p = $args->form_position ?? null;
        if ($p === null) {
            if ($this->display === self::DISP_SUBMISSION) {
                $p = 1100 + $this->position;
            } else if ($this->display === self::DISP_PROMINENT) {
                $p = 3100 + $this->position;
            } else {
                $p = 3600 + $this->position;
            }
        }
        $this->form_position = $p;

        if ($this->display < 0) {
            $p = false;
        }
        $this->display_position = $args->display_position ?? $p;
        $this->display_expand = !!($args->display_expand ?? false);
        $this->display_group = $args->display_group ?? null;
        if ($this->display_group === null
            && $this->display_position >= 3500
            && $this->display_position < 4000) {
            $this->display_group = "topics";
        }

        $this->list_class = $args->list_class ?? null;

        if (property_exists($args, "exists_if")) {
            $this->set_exists_if($args->exists_if);
        } else if (isset($args->edit_condition)) { // XXX backwards compat
            $this->set_exists_if($args->edit_condition);
        }

        $this->max_size = $args->max_size ?? null;

        if (($x = $args->editable_if ?? null) !== null && $x !== true) {
            $this->editable_if = $x;
            $this->_editable_search = new PaperSearch($this->conf->root_user(), $x === false ? "NONE" : $x);
        }
    }

    /** @param object $args
     * @return PaperOption */
    static function make($args, Conf $conf) {
        assert(is_object($args));
        Conf::xt_resolve_require($args);
        $fn = $args->function ?? $args->callback ?? null; /* XXX */
        if (!$fn) {
            $fn = self::$callback_map[$args->type ?? ""] ?? null;
        }
        if (!$fn) {
            $fn = "+Unknown_PaperOption";
        }
        if ($fn[0] === "+") {
            $class = substr($fn, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            return new $class($conf, $args);
        } else {
            return call_user_func($fn, $conf, $args);
        }
    }

    /** @param PaperOption $a
     * @param PaperOption $b */
    static function compare($a, $b) {
        $ap = $a->display_position();
        $ap = $ap !== false ? $ap : PHP_INT_MAX;
        $bp = $b->display_position();
        $bp = $bp !== false ? $bp : PHP_INT_MAX;
        return $ap <=> $bp ? : (strcasecmp($a->title, $b->title) ? : $a->id <=> $b->id);
    }

    /** @param PaperOption $a
     * @param PaperOption $b */
    static function form_compare($a, $b) {
        $ap = $a->form_position();
        $ap = $ap !== false ? $ap : PHP_INT_MAX;
        $bp = $b->form_position();
        $bp = $bp !== false ? $bp : PHP_INT_MAX;
        return $ap <=> $bp ? : (strcasecmp($a->title, $b->title) ? : $a->id <=> $b->id);
    }

    static function make_readable_formid($s) {
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', "-", UnicodeHelper::deaccent($s)));
        if (str_ends_with($s, "-")) {
            $s = substr($s, 0, -1);
        }
        if (!preg_match('/\A(?:title|paper|submission|final|authors|blind|nonblind|contacts|abstract|topics|pcconf|collaborators|submit.*|htctl.*|fold.*|pcc\d*|body.*|tracker.*|msg.*|header.*|footer.*|quicklink.*|tla.*|form-.*|has-.*|[-_].*|)\z/', $s)) {
            return $s;
        } else {
            return "field-" . $s;
        }
    }

    /** @return string */
    function title($context = null) {
        if ($this->title) {
            return $this->title;
        } else if ($context === null) {
            return $this->conf->_ci("field", $this->formid);
        } else {
            return $this->conf->_ci("field", $this->formid, null, $context);
        }
    }
    /** @return string */
    function title_html($context = null) {
        return htmlspecialchars($this->title($context));
    }
    /** @return string */
    function plural_title() {
        return $this->title ?? $this->conf->_ci("field/plural", $this->formid);
    }
    /** @return string */
    function edit_title() {
        return $this->title ?? $this->conf->_ci("field/edit", $this->formid);
    }
    /** @return string */
    function missing_title() {
        return $this->title ?? $this->conf->_ci("field/missing", $this->formid);
    }

    /** @return AbbreviationMatcher */
    private function abbrev_matcher() {
        if ($this->nonpaper) {
            return $this->conf->options()->nonpaper_abbrev_matcher();
        } else {
            return $this->conf->abbrev_matcher();
        }
    }
    /** @return string|false */
    function search_keyword() {
        if ($this->_search_keyword === null) {
            $am = $this->abbrev_matcher();
            assert($this->_search_keyword !== null);
        }
        return $this->_search_keyword;
    }
    /** @return string */
    function field_key() {
        return $this->formid;
    }
    /** @return string */
    function readable_formid() {
        if ($this->_readable_formid === null) {
            $used = [];
            foreach ($this->conf->options() as $o) {
                if ($o->_readable_formid !== null) {
                    $used[$o->_readable_formid] = true;
                }
            }
            foreach ($this->conf->options() as $o) {
                if ($o->_readable_formid === null && $o->id > 0) {
                    $s = self::make_readable_formid($o->title);
                    $o->_readable_formid = isset($used[$s]) ? $o->formid : $s;
                    $used[$o->_readable_formid] = true;
                }
            }
        }
        return $this->_readable_formid;
    }
    /** @return string */
    function json_key() {
        if ($this->_json_key === null) {
            if ($this->name !== null) {
                $am = $this->abbrev_matcher();
                $e = AbbreviationEntry::make_lazy($this->name, [$this->conf->options(), "option_by_id"], [$this->id], Conf::MFLAG_OPTION);
                $this->_json_key = $am->find_entry_keyword($e, AbbreviationMatcher::KW_UNDERSCORE | AbbreviationMatcher::KW_FULLPHRASE);
            }
            $this->_json_key = $this->_json_key ?? $this->formid;
        }
        return $this->_json_key;
    }
    /** @return string */
    function dtype_name() {
        return $this->id ? $this->json_key() : "paper";
    }
    /** @return string */
    function uid() {
        return $this->json_key();
    }

    /** @return int */
    function display() {
        return $this->display;
    }
    function form_position() {
        return $this->form_position;
    }
    function display_position() {
        return $this->display_position;
    }
    /** @return int */
    function visibility() {
        return $this->_visibility;
    }
    /** @return string */
    function unparse_visibility() {
        return self::$visibility_map[$this->_visibility];
    }

    /** @return bool */
    function test_exists(PaperInfo $prow) {
        return !$this->_exists_search || $this->_exists_search->test($prow);
    }
    /** @return ?string */
    function exists_condition() {
        return $this->exists_if;
    }
    function exists_script_expression(PaperInfo $prow) {
        $s = $this->_exists_search;
        return $s ? $s->term()->script_expression($prow) : null;
    }
    protected function set_exists_if($x) {
        if ($x !== null && $x !== true) {
            $this->exists_if = $x;
            $this->_exists_search = (new PaperSearch($this->conf->root_user(), $x === false ? "NONE" : $x))->set_expand_automatic(true);
        } else {
            $this->exists_if = $this->_exists_search = null;
        }
    }

    /** @return bool */
    function test_editable(PaperInfo $prow) {
        return !$this->_editable_search || $this->_editable_search->test($prow);
    }
    /** @return ?string */
    function editable_condition() {
        return $this->editable_if;
    }

    /** @return bool */
    function test_required(PaperInfo $prow) {
        // Invariant: `$o->test_required($prow)` implies `$o->required`.
        return $this->required && $this->test_exists($prow);
    }
    protected function set_required($x) {
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->required = $x;
    }

    /** @return bool */
    function is_document() {
        return false;
    }
    /** @return bool */
    function has_document() {
        return false;
    }
    /** @return bool */
    function allow_empty_document() {
        return false;
    }
    /** @return ?list<Mimetype|string> */
    function mimetypes() {
        return null;
    }
    /** @return bool */
    function has_attachments() {
        return false;
    }

    function value_force(PaperValue $ov) {
    }
    /** @return bool */
    function value_present(PaperValue $ov) {
        return !!$ov->value;
    }
    /** @param PaperValue $av
     * @param PaperValue $bv */
    function value_compare($av, $bv) {
        return 0;
    }
    /** @param PaperValue $av
     * @param PaperValue $bv */
    static function basic_value_compare($av, $bv) {
        $av = $av ? $av->value : null;
        $bv = $bv ? $bv->value : null;
        if ($av === $bv) {
            return 0;
        } else if ($av === null || $bv === null) {
            return $av === null ? -1 : 1;
        } else {
            return $av <=> $bv;
        }
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->test_required($ov->prow)
            && !$this->value_present($ov)
            && !$ov->prow->allow_absent()) {
            $ov->error("Entry required.");
        }
    }
    /** @return list<int> */
    function value_dids(PaperValue $ov) {
        return [];
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return null;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
    }
    /** @return bool */
    function value_save(PaperValue $ov, PaperStatus $ps) {
        return false;
    }

    /** @param string $name
     * @return ?DocumentInfo */
    function attachment(PaperValue $ov, $name) {
        return null;
    }

    function display_name() {
        if (!self::$display_rmap) {
            self::$display_rmap = array_flip(self::$display_map);
        }
        return self::$display_rmap[$this->display];
    }

    /** @return object */
    function jsonSerialize() {
        $j = (object) ["id" => (int) $this->id, "name" => $this->name];
        if ($this->type !== null) {
            $j->type = $this->type;
        }
        $j->position = (int) $this->position;
        if ($this->description !== null) {
            $j->description = $this->description;
        }
        if ($this->description_format !== null) {
            $j->description_format = $this->description_format;
        }
        if ($this->configurable !== true) {
            $j->configurable = $this->configurable;
        }
        if ($this->final) {
            $j->final = true;
        }
        $j->display = $this->display_name();
        if ($this->_visibility !== self::VIS_SUB) {
            $j->visibility = self::$visibility_map[$this->_visibility];
        }
        if ($this->exists_if !== null) {
            $j->exists_if = $this->exists_if;
        }
        if ($this->editable_if !== null) {
            $j->editable_if = $this->editable_if;
        }
        if ($this->required) {
            $j->required = true;
        }
        if ($this->max_size !== null) {
            $j->max_size = $this->max_size;
        }
        return $j;
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return false;
    }

    /** @return ?PaperValue */
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return null;
    }
    /** @return ?PaperValue */
    function parse_json(PaperInfo $prow, $j) {
        return null;
    }
    const PARSE_STRING_EMPTY = 1;
    const PARSE_STRING_TRIM = 2;
    const PARSE_STRING_SIMPLIFY = 4;
    const PARSE_STRING_CONVERT = 8;
    /** @return ?PaperValue */
    function parse_json_string(PaperInfo $prow, $j, $flags = 0) {
        if (is_string($j)) {
            if ($flags & self::PARSE_STRING_CONVERT) {
                $j = convert_to_utf8($j);
            }
            if ($flags & self::PARSE_STRING_SIMPLIFY) {
                $j = simplify_whitespace($j);
            } else if ($flags & self::PARSE_STRING_TRIM) {
                $j = rtrim($j);
                if ($j !== "" && ctype_space($j[0])) {
                    $j = preg_replace('/\A(?: {0,3}[\r\n]*)*/', "", $j);
                }
            }
            if ($j !== "" || ($flags & self::PARSE_STRING_EMPTY)) {
                return PaperValue::make($prow, $this, 1, $j);
            } else {
                return PaperValue::make($prow, $this);
            }
        } else if ($j === null) {
            return null;
        } else {
            return PaperValue::make_estop($prow, $this, "Expected string.");
        }
    }
    /** @param PaperValue $ov
     * @param PaperValue $reqov */
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
    }
    /** @param PaperValue $ov
     * @param PaperValue $reqov */
    function echo_web_edit_text(PaperTable $pt, $ov, $reqov, $extra = []) {
        $default_value = null;
        if ($ov->data() !== $reqov->data()
            && trim($ov->data()) !== trim(cleannl((string) $reqov->data()))) {
            $default_value = $ov->data() ?? "";
        }
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">';
        if (($fi = $ov->prow->edit_format())
            && !($extra["no_format_description"] ?? false)) {
            echo $fi->description_preview_html();
        }
        echo Ht::textarea($this->formid, $reqov->data(), [
                "id" => $this->readable_formid(),
                "class" => $pt->control_class($this->formid, "w-text need-autogrow"),
                "rows" => max($extra["rows"] ?? 1, 1),
                "cols" => 60,
                "spellcheck" => ($extra["no_spellcheck"] ?? null ? null : "true"),
                "readonly" => !$this->test_editable($ov->prow),
                "data-default-value" => $default_value
            ]),
            "</div></div>\n\n";
    }
    /** @param PaperInfo $prow
     * @return string */
    function web_edit_html(PaperInfo $prow, Contact $user) {
        ob_start();
        $pt = new PaperTable($user, new Qrequest("POST"), $prow);
        $ov = $prow->force_option($this);
        $this->echo_web_edit($pt, $ov, $ov);
        return ob_get_clean();
    }

    function validate_document(DocumentInfo $doc) {
        return true;
    }

    /** @param list<DocumentInfo|int> $docids */
    function save_document_links($docids, PaperInfo $prow) {
        $qv = [];
        foreach ($docids as $doc) {
            $doc = is_object($doc) ? $doc->paperStorageId : $doc;
            assert($doc > 0);
            if ($doc > 0) {
                $qv[] = [$prow->paperId, $this->id, $doc, null];
            }
        }
        $this->conf->qe("delete from PaperOption where paperId=? and optionId=?", $prow->paperId, $this->id);
        if (!empty($qv)) {
            for ($i = 0; count($qv) > 1 && $i < count($qv); ++$i) {
                $qv[$i][3] = $i + 1;
            }
            $this->conf->qe("insert into PaperOption (paperId, optionId, value, `data`) values ?v", $qv);
            $this->conf->qe("update PaperStorage set inactive=0 where paperId=? and paperStorageId?a", $prow->paperId, array_map(function ($qvx) { return $qvx[2]; }, $qv));
        }
        $prow->invalidate_options();
        $prow->mark_inactive_documents();
    }

    /** @return bool */
    function can_render($context) {
        if (($context & (FieldRender::CFLIST | FieldRender::CFLISTSUGGEST)) !== 0
            && (!is_string($this->list_class) || $this->search_keyword() === false)) {
            return false;
        }
        if (($context & FieldRender::CFLISTSUGGEST) !== 0
            && strpos($this->list_class, "pl-no-suggest") !== false) {
            return false;
        }
        if (($context & FieldRender::CFMAIL) !== 0
            && $this->search_keyword() === false) {
            return false;
        }
        if (($context & FieldRender::CFPAGE) !== 0
            && $this->display_position === false) {
            return false;
        }
        return true;
    }

    function render(FieldRender $fr, PaperValue $ov) {
    }

    /** @return ?FormatSpec */
    function format_spec() {
        return null;
    }

    const EXAMPLE_HELP = 0;
    const EXAMPLE_COMPLETION = 1;
    /** @param int $context
     * @return list<SearchExample> */
    function search_examples(Contact $viewer, $context) {
        return [];
    }
    /** @return SearchExample */
    function has_search_example() {
        assert($this->search_keyword() !== false);
        return new SearchExample("has:" . $this->search_keyword(), "submission field “%s” set", $this->title_html());
    }
    /** @return ?SearchTerm */
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return null;
    }
    /** @return ?SearchTerm */
    final function parse_boolean_search(SearchWord $sword, PaperSearch $srch) {
        $lword = strtolower($sword->cword);
        if (in_array($sword->compar, ["", "=", "!="], true)
            && in_array($lword, ["yes", "no", "y", "n", "true", "false"], true)) {
            $v = $lword[0] === "y" || $lword[0] === "t";
            return (new OptionPresent_SearchTerm($srch->user, $this))->negate_if($v !== ($sword->compar !== "!="));
        } else {
            return null;
        }
    }
    /** @return array|bool|null */
    function present_script_expression() {
        return null;
    }
    /** @return array|bool|null */
    function value_script_expression() {
        return null;
    }

    /** @param string &$t
     * @return ?Fexpr */
    function parse_fexpr(FormulaCall $fcall, &$t) {
        return null;
    }
    /** @return Fexpr */
    final function present_fexpr() {
        return new OptionPresent_Fexpr($this);
    }
}

class Separator_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        echo '<div class="pf pfe pf-separator">';
        if (($h = $pt->edit_title_html($this))) {
            echo '<h3 class="pfehead">', $h, '</h3>';
        }
        echo '</div>';
    }
}

class Checkbox_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->list_class = $this->list_class ?? "plc";
    }

    function value_compare($av, $bv) {
        return ($bv && $bv->value ? 1 : 0) - ($av && $av->value ? 1 : 0);
    }

    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $ov->value ? true : false;
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return PaperValue::make($prow, $this, $qreq[$this->formid] > 0 ? 1 : null);
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_bool($j) || $j === null) {
            return PaperValue::make($prow, $this, $j ? 1 : null);
        } else {
            return PaperValue::make_estop($prow, $this, "Option should be “true” or “false”.");
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $cb = Ht::checkbox($this->formid, 1, !!$reqov->value, [
            "id" => $this->readable_formid(),
            "data-default-checked" => !!$ov->value,
            "disabled" => !$this->test_editable($ov->prow)
        ]);
        $pt->echo_editable_option_papt($this,
            '<span class="checkc">' . $cb . '</span>' . $pt->edit_title_html($this),
            ["for" => "checkbox", "tclass" => "ui js-click-child"]);
        echo "</div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->for_page() && $ov->value) {
            $fr->title = "";
            $fr->set_html('✓ <span class="pavfn">' . $this->title_html() . '</span>');
        } else {
            $fr->set_bool(!!$ov->value);
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example()];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_boolean_search($sword, $srch);
    }
    function present_script_expression() {
        return ["type" => "checkbox", "id" => $this->id];
    }
    function value_script_expression() {
        return $this->present_script_expression();
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        return $this->present_fexpr();
    }
}

class Selector_PaperOption extends PaperOption {
    /** @var list<string> */
    private $selector;
    /** @var ?AbbreviationMatcher */
    private $_selector_am;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->selector = $args->selector ?? [];
        $this->list_class = $this->list_class ?? "";
    }

    function selector_options() {
        return $this->selector;
    }
    function set_selector_options($selector) {
        $this->selector = $selector;
    }
    function selector_abbrev_matcher() {
        if (!$this->_selector_am) {
            $this->_selector_am = new AbbreviationMatcher;
            foreach ($this->selector as $id => $name) {
                $this->_selector_am->add_phrase($name, $id + 1);
            }
            if (!$this->required) {
                $this->_selector_am->add_keyword("none", 0);
            }
        }
        return $this->_selector_am;
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        $j->selector = $this->selector;
        return $j;
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return $o instanceof Selector_PaperOption;
    }

    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $this->selector[$ov->value - 1] ?? null;
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        if ($v === "" || $v === "0") {
            return PaperValue::make($prow, $this);
        } else {
            $iv = ctype_digit($v) ? intval($v) : -1;
            if ($iv > 0 && isset($this->selector[$iv - 1])) {
                return PaperValue::make($prow, $this, $iv);
            } else if (($iv = array_search($v, $this->selector)) !== false) {
                return PaperValue::make($prow, $this, $iv + 1);
            } else {
                return PaperValue::make_estop($prow, $this, "Option doesn’t match any of the selectors.");
            }
        }
    }
    function parse_json(PaperInfo $prow, $j) {
        $v = false;
        if ($j === null || $j === 0) {
            return PaperValue::make($prow, $this);
        } else if (is_string($j)) {
            $v = array_search($j, $this->selector);
        } else if (is_int($j) && isset($this->selector[$j - 1])) {
            $v = $j - 1;
        }
        if ($v !== false) {
            return PaperValue::make($prow, $this, $v + 1);
        } else {
            return PaperValue::make_estop($prow, $this, "Option doesn’t match any of the selectors.");
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_option_papt($this, null,
            $this->type === "selector"
            ? ["for" => $this->readable_formid()]
            : ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev">';
        $readonly = !$this->test_editable($ov->prow);
        if ($this->type === "selector") {
            $sel = [];
            if (!$ov->value) {
                $sel[0] = "(Select one)";
            }
            foreach ($this->selector as $val => $text) {
                $sel[$val + 1] = $text;
            }
            echo Ht::select($this->formid, $sel, $reqov->value,
                ["id" => $this->readable_formid(),
                 "data-default-value" => $ov->value ?? 0,
                 "disabled" => $readonly]);
        } else {
            foreach ($this->selector as $val => $text) {
                echo '<div class="checki"><label><span class="checkc">',
                    Ht::radio($this->formid, $val + 1, $val + 1 == $reqov->value,
                        ["data-default-checked" => $val + 1 == $ov->value,
                         "disabled" => $readonly]),
                    '</span>', htmlspecialchars($text), '</label></div>';
            }
        }
        echo "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $fr->set_text($this->selector[$ov->value - 1] ?? "");
    }

    /** @return ?string */
    function selector_option_search($idx) {
        if ($idx <= 0) {
            return "none";
        } else if ($idx > count($this->selector)) {
            return null;
        } else {
            $e = new AbbreviationEntry($this->selector[$idx - 1], $idx);
            return $this->selector_abbrev_matcher()->find_entry_keyword($e, AbbreviationMatcher::KW_DASH);
        }
    }
    function search_examples(Contact $viewer, $context) {
        $a = [$this->has_search_example()];
        if (($q = $this->selector_option_search(2))) {
            $a[] = new SearchExample($this->search_keyword() . ":<selector>", "submission’s “%s” field has value “%s”", [$this->title_html(), htmlspecialchars($this->selector[1])], $q);
        }
        return $a;
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        $vs = $this->selector_abbrev_matcher()->findp($sword->cword);
        if (empty($vs)) {
            $pfx = htmlspecialchars($this->search_keyword()) . " (" . $this->title_html() . ")";
            if ($sword->cword === "") {
                $srch->warning("$pfx search: Selector missing.");
            } else if (($vs2 = $this->selector_abbrev_matcher()->find_all($sword->cword))) {
                $ts = array_map(function ($x) { return "“" . htmlspecialchars($this->selector[$x - 1]) . "”"; }, $vs2);
                $srch->warning("$pfx search: “" . htmlspecialchars($sword->cword) . "” matches more than one choice. Try " . commajoin($ts, " or ") . ", or use “" . htmlspecialchars($sword->cword) . "*” to match them all.");
            } else {
                $srch->warning("$pfx search: No choice matches “" . htmlspecialchars($sword->cword) . "”.");
            }
            return null;
        } else if (in_array($sword->compar, ["", "=", "!="], true)) {
            return (new OptionValueIn_SearchTerm($srch->user, $this, $vs))->negate_if($sword->compar === "!=");
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "selector", "id" => $this->id];
    }
    function value_script_expression() {
        return $this->present_script_expression();
    }
}

class Document_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->list_class = $this->list_class ?? "";
        if ($this->id === 0 && !$conf->opt("noPapers")) {
            $this->set_required(true);
        }
    }

    function is_document() {
        return true;
    }

    function has_document() {
        return true;
    }

    function mimetypes() {
        if ($this->type === "pdf" || $this->id <= 0) {
            return [Mimetype::checked_lookup(".pdf")];
        } else if ($this->type === "slides") {
            return [Mimetype::checked_lookup(".pdf"),
                    Mimetype::checked_lookup(".ppt"),
                    Mimetype::checked_lookup(".pptx")];
        } else if ($this->type === "video") {
            return [Mimetype::checked_lookup(".mp4"),
                    Mimetype::checked_lookup(".avi")];
        } else {
            return null;
        }
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return $o instanceof Document_PaperOption;
    }

    function value_force(PaperValue $ov) {
        if ($this->id === DTYPE_SUBMISSION && $ov->prow->paperStorageId > 1) {
            $ov->set_value_data([$ov->prow->paperStorageId], [null]);
        } else if ($this->id === DTYPE_FINAL && $ov->prow->finalPaperStorageId > 1) {
            $ov->set_value_data([$ov->prow->finalPaperStorageId], [null]);
        } else {
            $ov->set_value_data([], []);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value !== null && $ov->value > 1;
    }
    function value_compare($av, $bv) {
        return ($av && $av->value > 1 ? 1 : 0) - ($bv && $bv->value > 1 ? 1 : 0);
    }
    function value_dids(PaperValue $ov) {
        if ($ov->value > 1) {
            /** @phan-suppress-next-line ParamTypeMismatchReturn */
            return [$ov->value];
        } else {
            return [];
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        if (!$this->value_present($ov)) {
            return null;
        } else if (($doc = $ps->document_to_json($this, $ov->value))) {
            return $doc;
        } else {
            return false;
        }
    }
    function value_check(PaperValue $ov, Contact $user) {
        if ($this->id === DTYPE_SUBMISSION
            && !$this->conf->opt("noPapers")
            && ($ov->value ?? 0) <= 1
            && !$ov->prow->allow_absent()) {
            $ov->msg($this->conf->_("Entry required to complete submission."), MessageSet::WARNING_NOTE);
        }
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        if (($fup = $ov->anno("document"))) {
            if (($doc = $ps->upload_document($fup, $this))) {
                $ov->set_value_data([$doc->paperStorageId], [null]);
            } else {
                $ov->estop(null);
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        if ($this->id === DTYPE_SUBMISSION || $this->id === DTYPE_FINAL) {
            $ps->save_paperf($this->id ? "finalPaperStorageId" : "paperStorageId", $ov->value ?? 0);
            $ps->mark_diff($this->json_key());
            return true;
        } else {
            return false;
        }
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $fk = $this->field_key();
        $fk2 = "opt{$this->id}";
        if (($doc = DocumentInfo::make_request($qreq, $fk, $prow->paperId, $this->id, $this->conf))
            || // backward compat
               ($fk !== $fk2 && ($doc = DocumentInfo::make_request($qreq, $fk2, $prow->paperId, $this->id, $this->conf)))) {
            $ov = PaperValue::make($prow, $this, -1);
            $ov->set_anno("document", $doc);
            if (isset($doc->error_html)) {
                $ov->error($doc->error_html);
            }
            return $ov;
        } else if ($qreq["{$fk}:remove"] || ($fk !== $fk2 && $qreq["{$fk2}:remove"])) {
            return PaperValue::make($prow, $this);
        } else {
            return null;
        }
    }
    function parse_json(PaperInfo $prow, $j) {
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if ($j === null) {
            return null;
        } else if (DocumentInfo::check_json_upload($j)) {
            $ov = PaperValue::make($prow, $this, -1);
            $ov->set_anno("document", $j);
            if (isset($j->error_html)) {
                $ov->error($j->error_html);
            }
            return $ov;
        } else {
            return PaperValue::make_estop($prow, $this, "Format error.");
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        if ($this->id === DTYPE_SUBMISSION || $this->id === DTYPE_FINAL) {
            $noPapers = $this->conf->opt("noPapers");
            if ($noPapers === 1
                || $noPapers === true
                || ($this->id === DTYPE_FINAL) !== $pt->user->allow_edit_final_paper($ov->prow)) {
                return;
            }
        }

        // XXXX this is super gross
        if ($this->id > 0 && $ov->value) {
            $docid = $ov->value;
        } else if ($this->id === DTYPE_SUBMISSION
                   && $ov->prow->paperStorageId > 1) {
            $docid = $ov->prow->paperStorageId;
        } else if ($this->id === DTYPE_FINAL
                   && $ov->prow->finalPaperStorageId > 0) {
            $docid = $ov->prow->finalPaperStorageId;
        } else {
            $docid = 0;
        }
        $doc = null;
        if ($docid > 1 && $pt->user->can_view_pdf($ov->prow)) {
            $doc = $ov->prow->document($this->id, $docid, true);
        }

        $readonly = !$this->test_editable($ov->prow);
        $max_size = $this->max_size ?? $this->conf->opt("uploadMaxFilesize") ?? ini_get_bytes("upload_max_filesize") / 1.024;
        $fk = $this->field_key();

        // heading
        $msgs = [];
        $mimetypes = $this->mimetypes();
        if ($mimetypes) {
            $msgs[] = htmlspecialchars(Mimetype::list_description($mimetypes));
        }
        if ($max_size > 0) {
            $msgs[] = "max " . unparse_byte_size($max_size);
        }
        $heading = $pt->edit_title_html($this);
        if (!empty($msgs)) {
            $heading .= ' <span class="n">(' . join(", ", $msgs) . ')</span>';
        }
        $pt->echo_editable_option_papt($this, $heading, ["for" => $doc ? false : "{$fk}:upload", "id" => $this->readable_formid()]);

        echo '<div class="papev has-document" data-dtype="', $this->id,
            '" data-document-name="', $fk, '"';
        if ($doc) {
            echo ' data-docid="', $doc->paperStorageId, '"';
        }
        if ($mimetypes) {
            echo ' data-document-accept="', htmlspecialchars(join(",", array_map(function ($m) { return $m->mimetype; }, $mimetypes))), '"';
        }
        if ($this->max_size !== null) {
            echo ' data-document-max-size="', (int) $this->max_size, '"';
        }
        echo '>';

        // current version, if any
        $has_cf = false;
        if ($doc) {
            if ($doc->mimetype === "application/pdf") {
                if (!$pt->cf) {
                    $pt->cf = new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
                }
                $spec = $this->conf->format_spec($this->id);
                $has_cf = $spec && !$spec->is_empty();
                if ($has_cf) {
                    $pt->cf->check_document($doc);
                }
            }

            echo '<div class="document-file">',
                $doc->link_html(htmlspecialchars($doc->filename ?? "")),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc))) {
                echo $stamps;
            }
            echo '</div><div class="document-actions">';
            if ($this->id > 0 && !$readonly) {
                echo '<a href="" class="ui js-remove-document document-action">Delete</a>';
            }
            if ($has_cf && $pt->cf->allow_recheck()) {
                echo '<a href="" class="ui js-check-format document-action">',
                    ($pt->cf->need_recheck() ? "Check format" : "Recheck format"),
                    '</a>';
            } else if ($has_cf && !$pt->cf->has_problem()) {
                echo '<span class="document-action js-check-format dim">Format OK</span>';
            }
            echo '</div>';
            if ($has_cf) {
                echo '<div class="document-format">';
                if ($pt->cf->has_problem() && $pt->cf->check_ok()) {
                    echo $pt->cf->document_report($doc);
                }
                echo '</div>';
            }
        }

        if (!$readonly) {
            echo '<div class="document-replacer">', Ht::button($doc ? "Replace" : "Upload", ["class" => "ui js-replace-document", "id" => "{$fk}:upload"]), '</div>';
        }
        echo "</div></div>\n\n";
    }

    function validate_document(DocumentInfo $doc) {
        $mimetypes = $this->mimetypes();
        if (empty($mimetypes)) {
            return true;
        }
        for ($i = 0; $i < count($mimetypes); ++$i) {
            if ($mimetypes[$i]->mimetype === $doc->mimetype)
                return true;
        }
        $desc = htmlspecialchars(Mimetype::list_description($mimetypes));
        $e = "I only accept $desc files."
            . " (Your file has MIME type “" . htmlspecialchars($doc->mimetype) . "” and "
            . htmlspecialchars($doc->content_text_signature())
            . ".)<br>Please convert your file to "
            . (count($mimetypes) > 3 ? "a supported type" : $desc)
            . " and try again.";
        $doc->add_error_html($e);
        return false;
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($this->id <= 0 && $fr->for_page()) {
            if ($this->id == 0) {
                $fr->table->render_submission($fr, $this);
            }
        } else if (($d = $ov->document(0))) {
            if ($fr->want_text()) {
                $fr->set_text($d->filename);
            } else if ($fr->for_page()) {
                $fr->title = "";
                $dif = 0;
                if ($this->display_position() >= 2000) {
                    $dif = DocumentInfo::L_SMALL;
                }
                $fr->set_html($d->link_html('<span class="pavfn">' . $this->title_html() . '</span>', $dif));
            } else {
                $fr->set_html($d->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE));
            }
        }
    }

    function format_spec() {
        $speckey = "sub_banal";
        if ($this->id) {
            $speckey .= ($this->id < 0 ? "_m" : "_") . abs($this->id);
        }
        $fspec = new FormatSpec;
        if (($xspec = $this->conf->opt($speckey))) {
            $fspec->merge($xspec, $this->conf->opt_timestamp());
        }
        if (($spects = $this->conf->setting($speckey)) > 0) {
            $fspec->merge($this->conf->setting_data($speckey) ?? "", $spects);
        } else if ($spects < 0) {
            $fspec->clear_banal();
        }
        if (!$fspec->is_banal_empty()) {
            $fspec->merge_banal();
        }
        return $fspec;
    }

    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example()];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_boolean_search($sword, $srch);
    }
    function present_script_expression() {
        return ["type" => "document_count", "id" => $this->id];
    }
}

class Numeric_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->list_class = $this->list_class ?? "plrd";
    }

    function value_present(PaperValue $ov) {
        return $ov->value !== null;
    }
    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        return $ov->value;
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        $iv = intval($v);
        if (is_numeric($v) && (float) $iv === floatval($v)) {
            $ov = PaperValue::make($prow, $this, $iv);
        } else if (preg_match('/\A(?:n\/?a|none|)\z/i', $v)) {
            $ov = PaperValue::make($prow, $this);
        } else {
            $ov = PaperValue::make_estop($prow, $this, "Integer expected.");
        }
        $ov->set_anno("request", $v);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_int($j)) {
            return PaperValue::make($prow, $this, $j);
        } else if ($j === null || $j === false) {
            return PaperValue::make($prow, $this);
        } else {
            return PaperValue::make_estop($prow, $this, "Integer expected.");
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $reqx = $reqov->anno("request") ?? $reqov->value ?? "";
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqx, [
                "id" => $this->readable_formid(), "size" => 8,
                "size" => 8, "inputmode" => "numeric",
                "class" => "js-autosubmit" . $pt->has_error_class($this->formid),
                "data-default-value" => $ov->value ?? "",
                "readonly" => !$this->test_editable($ov->prow)
            ]),
            "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value !== null) {
            $fr->set_text((string) $ov->value);
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample($this->search_keyword() . ":<comparator>", "submission’s “%s” field is greater than 100", $this->title_html(), ">100")
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (preg_match('/\A[-+]?(?:\d+|\d+\.\d*|\.\d+)\z/', $sword->cword)) {
            return new OptionValue_SearchTerm($srch->user, $this, CountMatcher::parse_relation($sword->compar), (float) $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "text_present", "id" => $this->id];
    }
    function value_script_expression() {
        return ["type" => "numeric", "id" => $this->id];
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        return new OptionValue_Fexpr($this);
    }
}

class Text_PaperOption extends PaperOption {
    public $display_space;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->display_space = $args->display_space ?? 0;
        $this->list_class = $this->list_class ?? "pl-prefer-row pl_text";
    }

    static function expand($name, Contact $user, $fxt, $m) {
        $xt = clone $fxt;
        unset($xt->match);
        $xt->name = $name;
        $xt->display_space = +$m[1];
        $xt->title = "Multiline text ({$m[1]} lines)";
        return $xt;
    }

    function value_present(PaperValue $ov) {
        return (string) $ov->data() !== "";
    }
    function value_compare($av, $bv) {
        $av = $av ? (string) $av->data() : "";
        $bv = $bv ? (string) $bv->data() : "";
        if ($av !== "" && $bv !== "") {
            return $this->conf->collator()->compare($av, $bv);
        } else {
            return ($bv !== "" ? 1 : 0) - ($av !== "" ? 1 : 0);
        }
    }

    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $x = $ov->data();
        return $x !== "" ? $x : null;
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, convert_to_utf8($qreq[$this->formid]));
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j);
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $this->echo_web_edit_text($pt, $ov, $reqov, ["rows" => $this->display_space]);
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $d = $ov->data();
        if ($d !== null && $d !== "") {
            $fr->value = $d;
            $fr->value_format = $ov->prow->format_of($d);
            $fr->value_long = true;
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample($this->search_keyword() . ":<text>", "submission’s “%s” field contains “hello”", $this->title_html(), "hello")
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if ($sword->compar === "") {
            return new OptionText_SearchTerm($srch->user, $this, $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "text_present", "id" => $this->id];
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->display_space) {
            $j->display_space = $this->display_space;
        }
        return $j;
    }
}

class Attachments_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->list_class = $this->list_class ?? "pl-prefer-row";
    }

    function has_document() {
        return true;
    }
    function has_attachments() {
        return true;
    }

    function attachment(PaperValue $ov, $name) {
        return $ov->document_set()->document_by_filename($name);
    }

    function value_compare($av, $bv) {
        return ($av && $av->value_count() ? 1 : 0) - ($bv && $bv->value_count() ? 1 : 0);
    }
    function value_dids(PaperValue $ov) {
        $j = null;
        foreach ($ov->data_list() as $d) {
            if ($d !== null && str_starts_with($d, "{"))
                $j = json_decode($d);
        }
        if ($j && isset($j->all_dids)) {
            return $j->all_dids;
        } else {
            $values = $ov->value_list();
            $data = $ov->data_list();
            array_multisort($data, SORT_NUMERIC, $values);
            return $values;
        }
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $attachments = [];
        foreach ($ov->documents() as $doc) {
            if (($doc = $ps->document_to_json($this, $doc)))
                $attachments[] = $doc;
        }
        return empty($attachments) ? null : $attachments;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        $dids = $ov->anno("dids") ?? [];
        '@phan-var list<int> $dids';
        $fups = $ov->anno("documents") ?? [];
        '@phan-var list<object> $fups';
        foreach ($fups as $fup) {
            if (($doc = $ps->upload_document($fup, $this))) {
                $dids[] = $doc->paperStorageId;
            }
        }
        if (empty($dids)) {
            $ov->set_value_data([], []);
        } else if (count($dids) == 1) {
            $ov->set_value_data([$dids[0]], [null]);
        } else {
            // Put the ordered document IDs in the first option’s sort data.
            // This is so (1) the link from option -> PaperStorage is visible
            // directly via PaperOption.value, (2) we can still support
            // duplicate uploads.
            $uniqdids = array_values(array_unique($dids, SORT_NUMERIC));
            $datas = array_fill(0, count($uniqdids), null);
            $datas[0] = json_encode(["all_dids" => $dids]);
            $ov->set_value_data($uniqdids, $datas);
        }
    }

    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $dids = $anno = [];
        $ov = PaperValue::make($prow, $this, -1);
        foreach ($this->value_dids($prow->force_option($this)) as $i => $did) {
            if (!isset($qreq["{$this->formid}_{$did}_{$i}:remove"])) {
                $ov->push_anno("dids", $did);
            }
        }
        for ($i = 1; isset($qreq["has_{$this->formid}_new_$i"]); ++$i) {
            if (($doc = DocumentInfo::make_request($qreq, "{$this->formid}_new_$i", $prow->paperId, $this->id, $this->conf))) {
                if (isset($doc->error_html)) {
                    $ov->error($doc->error_html);
                }
                $ov->push_anno("documents", $doc);
            }
        }
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if ($j === null) {
            return null;
        } else {
            $ja = is_array($j) ? $j : [$j];
            $ov = PaperValue::make($prow, $this, -1);
            $ov->set_anno("documents", $ja);
            foreach ($ja as $docj) {
                if (is_object($docj) && isset($docj->error_html)) {
                    $ov->error($docj->error_html);
                } else if (!DocumentInfo::check_json_upload($docj)) {
                    $ov->estop("Format error.");
                }
            }
            return $ov;
        }
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        // XXX does not consider $reqov
        $max_size = $this->max_size ?? $this->conf->opt("uploadMaxFilesize") ?? ini_get_bytes("upload_max_filesize") / 1.024;
        $title = $this->title_html();
        if ($max_size > 0) {
            $title .= ' <span class="n">(max ' . unparse_byte_size($max_size) . ' per file)</span>';
        }
        $pt->echo_editable_option_papt($this, $title, ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev has-editable-attachments" data-document-prefix="', $this->formid, '" data-dtype="', $this->id, '" id="', $this->formid, ':attachments"';
        if ($max_size > 0) {
            echo ' data-document-max-size="', (int) $max_size, '"';
        }
        echo '>';
        $readonly = !$this->test_editable($ov->prow);
        foreach ($ov->document_set() as $i => $doc) {
            $oname = "{$this->formid}_{$doc->paperStorageId}_{$i}";
            echo '<div class="has-document" data-dtype="', $this->id,
                '" data-document-name="', $oname, '"><div class="document-file">',
                $doc->link_html(htmlspecialchars($doc->member_filename())),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc))) {
                echo $stamps;
            }
            echo '</div>';
            if (!$readonly) {
                echo '<div class="document-actions">', Ht::link("Delete", "", ["class" => "ui js-remove-document document-action"]), '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        if (!$readonly) {
            echo Ht::button("Add attachment", ["class" => "ui js-add-attachment", "data-editable-attachments" => "{$this->formid}:attachments"]);
        }
        echo "</div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $ts = [];
        foreach ($ov->document_set() as $d) {
            if ($fr->want_text()) {
                $ts[] = $d->member_filename();
            } else {
                $linkname = htmlspecialchars($d->member_filename());
                if ($fr->want_list()) {
                    $dif = DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE;
                } else if ($this->display_position() >= 2000) {
                    $dif = DocumentInfo::L_SMALL;
                } else {
                    $dif = 0;
                    $linkname = '<span class="pavfn">' . $this->title_html() . '</span>/' . $linkname;
                }
                $t = $d->link_html($linkname, $dif);
                if ($d->is_archive()) {
                    $t = '<span class="archive foldc"><a href="" class="ui js-expand-archive qq">' . expander(null, 0) . '</a> ' . $t . '</span>';
                }
                $ts[] = $t;
            }
        }
        if (!empty($ts)) {
            if ($fr->want_text()) {
                $fr->set_text(join("; ", $ts));
            } else if ($fr->want_list_row()) {
                $fr->set_html(join("; ", $ts));
            } else {
                $fr->set_html('<p class="od">' . join('</p><p class="od">', $ts) . '</p>');
            }
            if ($fr->for_page() && $this->display_position() < 2000) {
                $fr->title = false;
                $v = '<div class="pgsm';
                if ($fr->table && $fr->user->view_option_state($ov->prow, $this) === 1) {
                    $v .= ' fx8';
                }
                $fr->value = $v . '">' . $fr->value . '</div>';
            }
        } else if ($fr->verbose()) {
            $fr->set_text("None");
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample($this->search_keyword() . ":<count>", "submission has three or more “%s” attachments", $this->title_html(), ">2"),
            new SearchExample($this->search_keyword() . ":\"<filename>\"", "submission has “%s” attachment matching “*.gif”", $this->title_html(), "*.gif")
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (preg_match('/\A[-+]?\d+\z/', $sword->cword)) {
            return new DocumentCount_SearchTerm($srch->user, $this, $sword->compar, (int) $sword->cword);
        } else if ($sword->compar === "" || $sword->compar === "!=") {
            return new DocumentName_SearchTerm($srch->user, $this, $sword->compar !== "!=", $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "document_count", "id" => $this->id];
    }
}

class Unknown_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        $args->type = "__unknown" . $args->id . "__";
        $args->form_position = $args->display_position = false;
        parent::__construct($conf, $args);
    }
}
