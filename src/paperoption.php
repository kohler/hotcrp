<?php
// paperoption.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class PaperOptionValue {
    public $prow;
    public $id;
    public $option;
    public $value;
    private $_values;
    private $_data;
    private $_data_array;
    public $anno = null;
    private $_documents = null;

    function __construct($prow, PaperOption $o, $values = [], $data_array = []) {
        $this->prow = $prow;
        $this->id = $o->id;
        $this->option = $o;
        if (empty($values) && $prow && $o->id <= -1000)
            $this->assign_intrinsic();
        else
            $this->assign_value_data([$values, $data_array]);
    }
    function assign_value_data($value_data) {
        $old_values = $this->_values;
        $this->_values = $value_data[0];
        $this->_data_array = $value_data[1];
        if (count($this->_values) > 1 && $this->_data_array !== null)
            $this->option->expand_values($this->_values, $this->_data_array);
        if (count($this->_values) == 1 || !$this->option->takes_multiple()) {
            $this->value = get($this->_values, 0);
            $this->_data = empty($this->_data_array) ? null : $this->_data_array[0];
        } else
            $this->value = $this->_data = null;
        $this->anno = null;
        if ($this->_documents !== null && $this->_values != $old_values)
            $this->_documents = null;
    }
    private function assign_intrinsic() {
        $s = null;
        if ($this->id === PaperOption::TITLEID) {
            $s = $this->prow->title;
        } else if ($this->id === PaperOption::ABSTRACTID) {
            $s = $this->prow->abstract;
        } else if ($this->id === PaperOption::COLLABORATORSID) {
            $s = $this->prow->collaborators;
        }
        if ($s !== null && $s !== "") {
            $this->assign_value_data([[1], [$s]]);
        } else {
            $this->assign_value_data([[], []]);
        }
    }
    function documents() {
        assert($this->prow || empty($this->_values));
        assert($this->option->has_document());
        if ($this->_documents === null) {
            $this->option->refresh_documents($this);
            $this->_documents = [];
            foreach ($this->sorted_values() as $docid)
                if ($docid > 1
                    && ($d = $this->prow->document($this->id, $docid)))
                    $this->_documents[] = $d;
            DocumentInfo::assign_unique_filenames($this->_documents);
        }
        return $this->_documents;
    }
    function document($index) {
        return get($this->documents(), $index);
    }
    function document_content($index) {
        $doc = $this->document($index);
        return $doc ? $doc->content() : false;
    }
    function document_by_id($docid) {
        foreach ($this->documents() as $doc)
            if ($doc->paperStorageId == $docid)
                return $doc;
        return null;
    }
    function attachment($name) {
        return $this->option->attachment($this, $name);
    }
    function value_count() {
        return count($this->_values);
    }
    function unsorted_values() {
        return $this->_values;
    }
    function sorted_values() {
        if ($this->_data_array === null && count($this->_values) > 1)
            $this->assign_value_data($this->prow->option_value_data($this->id));
        return $this->_values;
    }
    function data() {
        if ($this->_data_array === null)
            $this->assign_value_data($this->prow->option_value_data($this->id));
        return $this->_data;
    }
    function invalidate() {
        $this->prow->invalidate_options(true);
        $this->assign_value_data($this->prow->option_value_data($this->id));
    }
}

class FieldRender {
    public $table;
    public $context;
    public $title;
    public $value;
    public $value_format;
    public $value_long;

    const CFHTML = 1;
    const CFPAGE = 2;
    const CFLIST = 4;
    const CFCOLUMN = 8;
    const CFCSV = 16;
    const CFVERBOSE = 32;

    const CTEXT = 0;
    const CPAGE = 3;

    function __construct($context) {
        $this->context = $context;
    }
    function clear($context = null) {
        if ($context !== null) {
            $this->context = $context;
        }
        $this->title = null;
        $this->value = $this->value_format = $this->value_long = null;
    }
    function is_empty() {
        return (string) $this->title === "" && (string) $this->value === "";
    }
    function for_page() {
        return ($this->context & self::CFPAGE) !== 0;
    }
    function want_text() {
        return ($this->context & self::CFHTML) === 0;
    }
    function want_html() {
        return ($this->context & self::CFHTML) !== 0;
    }
    function want_list() {
        return ($this->context & self::CFLIST) !== 0;
    }
    function want_list_row() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) === self::CFLIST;
        }
    function want_list_column() {
        return ($this->context & (self::CFLIST | self::CFCOLUMN)) ===
            (self::CFLIST | self::CFCOLUMN);
    }
    function verbose() {
        return ($this->context & self::CFVERBOSE) !== 0;
    }
    function set_text($t) {
        $this->value = $t;
        $this->value_format = 0;
    }
    function set_html($t) {
        $this->value = $t;
        $this->value_format = 5;
    }
    function set_bool($b) {
        $v = $this->verbose();
        if ($this->context & self::CFHTML) {
            $this->set_text($b ? "✓" : ($v ? "✗" : ""));
        } else if ($this->context & self::CFCSV) {
            $this->set_text($b ? "Y" : ($v ? "N" : ""));
        } else {
            $this->set_text($b ? "Yes" : ($v ? "No" : ""));
        }
    }
    function value_html($divclass = null) {
        $rest = "";
        if ((string) $this->value === "") {
            return "";
        } else if ($this->value_format === 5) {
            if ($divclass === null) {
                return $this->value;
            }
            $html = $this->value;
        } else if ($this->value_format === 0) {
            if ($this->value_long) {
                $html = Ht::format0($this->value);
                $divclass = $divclass ? "format0 " . $divclass : "format0";
            } else {
                $html = htmlspecialchars($this->value);
            }
        } else {
            Ht::stash_script('$(render_text.on_page)', 'render_on_page');
            $html = htmlspecialchars($this->value);
            $divclass = $divclass ? "need-format " . $divclass : "need-format";
            $rest = ' data-format="' . $this->value_format . '"';
        }
        if ($divclass || $rest) {
            $html = '<div' . ($divclass ? ' class="' . $divclass . '"' : "")
                . $rest . '>' . $html . '</div>';
        }
        return $html;
    }
}

class PaperOptionList {
    private $conf;
    private $_jlist;
    private $_omap = [];
    private $_ijlist;
    private $_imap = [];
    private $_olist;
    private $_olist_nonfinal;
    private $_olist_include_empty;
    private $_nonpaper_am;
    private $_adding_fixed;

    const DTYPE_SUBMISSION_JSON = '{"id":0,"name":"paper","json_key":"paper","readable_formid":"submission","form_position":1001,"type":"document"}';
    const DTYPE_FINAL_JSON = '{"id":-1,"name":"final","json_key":"final","form_position":1002,"display_position":false,"type":"document"}';

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function _add_json($oj, $k, $landmark) {
        if (!isset($oj->id) && $k === 0) {
            error_log("{$this->conf->dbname}: old-style options JSON");
            // XXX backwards compat
            $ok = true;
            foreach (get_object_vars($oj) as $kk => $vv)
                if (is_object($vv)) {
                    if (!isset($vv->id))
                        $vv->id = $kk;
                    $ok = $this->_add_json($vv, $kk, $landmark) && $ok;
                }
            return $ok;
        }
        if (is_string($oj->id) && is_numeric($oj->id)) // XXX backwards compat
            $oj->id = intval($oj->id);
        if (is_int($oj->id)
            && $oj->id > 0
            && ($oj->id >= PaperOption::MINFIXEDID) === $this->_adding_fixed) {
            if ($this->conf->xt_allowed($oj)
                && (!isset($this->_jlist[$oj->id])
                    || Conf::xt_priority_compare($oj, $this->_jlist[$oj->id]) <= 0)) {
                $this->_jlist[$oj->id] = $oj;
                if (isset($oj->include_empty) && $oj->include_empty)
                    $this->_olist_include_empty = true;
            }
            return true;
        } else {
            return false;
        }
    }

    private function option_json_list() {
        if ($this->_jlist === null) {
            $this->_jlist = [];
            $this->_olist_include_empty = [];
            if (($olist = $this->conf->setting_json("options"))) {
                $this->_adding_fixed = false;
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            if (($olist = $this->conf->opt("fixedOptions"))) {
                $this->_adding_fixed = true;
                expand_json_includes_callback($olist, [$this, "_add_json"]);
            }
            $this->_jlist = array_filter($this->_jlist, "Conf::xt_enabled");
        }
        return $this->_jlist;
    }

    function populate_abbrev_matcher(AbbreviationMatcher $am) {
        $cb = [$this, "get"];
        $am->add_lazy("paper", $cb, [DTYPE_SUBMISSION], Conf::FSRCH_OPTION, 1);
        $am->add_lazy("submission", $cb, [DTYPE_SUBMISSION], Conf::FSRCH_OPTION, 1);
        $am->add_lazy("final", $cb, [DTYPE_FINAL], Conf::FSRCH_OPTION, 1);
        foreach ($this->option_json_list() as $id => $oj) {
            if (!get($oj, "nonpaper")) {
                $am->add_lazy($oj->name, $cb, [$id], Conf::FSRCH_OPTION);
                $am->add_lazy("opt{$id}", $cb, [$id], Conf::FSRCH_OPTION, 1);
            }
        }
    }

    function _add_intrinsic_json($oj, $k, $landmark) {
        assert(is_int($oj->id) && $oj->id <= 0);
        $this->_ijlist[(string) $oj->id][] = $oj;
        return true;
    }

    private function intrinsic_json_list() {
        if ($this->_ijlist === null) {
            $this->_ijlist = [];
            expand_json_includes_callback(["etc/intrinsicoptions.json", self::DTYPE_SUBMISSION_JSON, self::DTYPE_FINAL_JSON, $this->conf->opt("intrinsicOptions"), $this->conf->setting_json("ioptions")], [$this, "_add_intrinsic_json"]);
            $nijlist = [];
            foreach ($this->_ijlist as $id => $x)
                if (($ij = $this->conf->xt_search_name($this->_ijlist, $id, null)))
                    $nijlist[$ij->id] = $ij;
            $this->_ijlist = $nijlist;
        }
        return $this->_ijlist;
    }

    private function populate_intrinsic($id) {
        if ($id == DTYPE_SUBMISSION) {
            $this->_imap[$id] = new DocumentPaperOption($this->conf, json_decode(self::DTYPE_SUBMISSION_JSON, true));
        } else if ($id == DTYPE_FINAL) {
            $this->_imap[$id] = new DocumentPaperOption($this->conf, json_decode(self::DTYPE_FINAL_JSON, true));
        } else {
            $this->_imap[$id] = null;
            if (($oj = get($this->intrinsic_json_list(), $id))
                && ($o = PaperOption::make($oj, $this->conf)))
                $this->_imap[$id] = $o;
        }
    }

    function get($id, $force = false) {
        if ($id <= 0) {
            if (!array_key_exists($id, $this->_imap))
                $this->populate_intrinsic($id);
            return $this->_imap[$id];
        }
        if (!array_key_exists($id, $this->_omap)) {
            $this->_omap[$id] = null;
            if (($oj = get($this->option_json_list(), $id))
                && ($o = PaperOption::make($oj, $this->conf))
                && $this->conf->xt_allowed($o)
                && !Conf::xt_disabled($o))
                $this->_omap[$id] = $o;
        }
        $o = $this->_omap[$id];
        if (!$o && $force) {
            $o = $this->_omap[$id] = new UnknownPaperOption($this->conf, ["id" => $id]);
        }
        return $o;
    }

    function option_list() {
        if ($this->_olist === null) {
            $this->_olist = [];
            foreach ($this->option_json_list() as $id => $oj) {
                if (!get($oj, "nonpaper")
                    && ($o = $this->get($id))) {
                    assert(!$o->nonpaper);
                    $this->_olist[$id] = $o;
                }
            }
            uasort($this->_olist, "PaperOption::compare");
        }
        return $this->_olist;
    }

    function nonfixed_option_list() {
        return array_filter($this->option_list(), function ($o) {
            return $o->id < PaperOption::MINFIXEDID;
        });
    }

    function nonfinal_option_list() {
        if ($this->_olist_nonfinal === null) {
            $this->_olist_nonfinal = [];
            foreach ($this->option_json_list() as $id => $oj)
                if (!get($oj, "nonpaper")
                    && !get($oj, "final")
                    && ($o = $this->get($id))) {
                    assert(!$o->nonpaper && !$o->final);
                    $this->_olist_nonfinal[$id] = $o;
                }
            uasort($this->_olist_nonfinal, "PaperOption::compare");
        }
        return $this->_olist_nonfinal;
    }

    function full_option_list() {
        $list = [];
        foreach ($this->option_json_list() as $id => $oj) {
            if (($o = $this->get($id)))
                $list[$id] = $o;
        }
        uasort($list, "PaperOption::compare");
        return $list;
    }

    function include_empty_option_list() {
        if ($this->_olist_include_empty === null)
            $this->option_json_list();
        if ($this->_olist_include_empty === true) {
            $this->_olist_include_empty = [];
            foreach ($this->option_json_list() as $id => $oj) {
                if (get($oj, "include_empty")
                    && !get($oj, "nonpaper")
                    && ($o = $this->get($id))) {
                    $this->_olist_include_empty[$id] = $o;
                }
            }
            uasort($this->_olist_include_empty, "PaperOption::compare");
        }
        return $this->_olist_include_empty;
    }

    private function _get_field($id, $oj, $nonfinal) {
        if (get($oj, "nonpaper")
            || ($nonfinal && get($oj, "final")))
            return null;
        return $this->get($id);
    }

    function field_list(PaperInfo $prow = null) {
        $nonfinal = !$prow || $prow->outcome <= 0;
        $olist = [];
        foreach ($this->intrinsic_json_list() as $id => $oj)
            if (($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[$id] = $o;
        foreach ($this->option_json_list() as $id => $oj)
            if (($o = $this->_get_field($id, $oj, $nonfinal)))
                $olist[$id] = $o;
        uasort($olist, "PaperOption::compare");
        return $olist;
    }

    function invalidate_option_list() {
        $this->_jlist = $this->_olist = $this->_olist_nonfinal =
            $this->_nonpaper_am = $this->_olist_include_empty = null;
        $this->_omap = [];
    }

    function count_option_list() {
        return count($this->option_json_list());
    }

    function find_all($name) {
        $iname = strtolower($name);
        if ($iname === (string) DTYPE_SUBMISSION
            || $iname === "paper"
            || $iname === "submission")
            return [DTYPE_SUBMISSION => $this->get(DTYPE_SUBMISSION)];
        else if ($iname === (string) DTYPE_FINAL
                 || $iname === "final")
            return [DTYPE_FINAL => $this->get(DTYPE_FINAL)];
        if ($iname === "" || $iname === "none")
            return [];
        if ($iname === "any")
            return $this->option_list();
        if (substr($iname, 0, 3) === "opt"
            && ctype_digit(substr($iname, 3))) {
            $o = $this->get((int) substr($iname, 3));
            return $o ? [$o->id => $o] : [];
        }
        if (substr($iname, 0, 4) === "opt-")
            $name = substr($name, 4);
        $omap = [];
        foreach ($this->conf->find_all_fields($name, Conf::FSRCH_OPTION) as $o)
            $omap[$o->id] = $o;
        return $omap;
    }

    function find($name, $nonpaper = false) {
        assert(!$nonpaper);
        $omap = $this->find_all($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }

    function nonpaper_abbrev_matcher() {
        // Nonpaper options aren't stored in the main abbrevmatcher; put them
        // in their own.
        if (!$this->_nonpaper_am) {
            $this->_nonpaper_am = new AbbreviationMatcher;
            foreach ($this->option_json_list() as $id => $oj)
                if (get($oj, "nonpaper")
                    && ($o = $this->get($id))) {
                    assert($o->nonpaper);
                    $this->_nonpaper_am->add($o->name, $o);
                    $this->_nonpaper_am->add($o->formid, $o);
                }
        }
        return $this->_nonpaper_am;
    }

    function find_all_nonpaper($name) {
        $omap = [];
        foreach ($this->nonpaper_abbrev_matcher()->find_all($name) as $o)
            $omap[$o->id] = $o;
        return $omap;
    }

    function find_nonpaper($name) {
        $omap = $this->find_all_nonpaper($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }
}

class PaperOption implements Abbreviator {
    const MINFIXEDID = 1000000;
    const TITLEID = -1000;
    const ABSTRACTID = -1004;
    const AUTHORSID = -1001;
    const CONTACTSID = -1003;
    const PCCONFID = -1006;
    const COLLABORATORSID = -1007;

    public $conf;
    public $id;
    public $name;
    public $formid;
    public $readable_formid;
    private $title;
    public $type; // checkbox, selector, radio, numeric, text,
                  // pdf, slides, video, attachments, ...
    private $_json_key;
    private $_search_keyword;
    public $description;
    public $description_format;
    public $position;
    public $required;
    public $final;
    public $nonpaper;
    public $visibility; // "rev", "nonblind", "admin"
    private $display;
    public $display_expand;
    public $display_group;
    public $display_space;
    private $form_position;
    private $display_position;
    private $exists_if;
    private $_exists_search;
    private $editable_if;
    private $_editable_search;

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
        "checkbox" => "+CheckboxPaperOption",
        "radio" => "+SelectorPaperOption",
        "selector" => "+SelectorPaperOption",
        "numeric" => "+NumericPaperOption",
        "text" => "+TextPaperOption",
        "pdf" => "+DocumentPaperOption",
        "slides" => "+DocumentPaperOption",
        "video" => "+DocumentPaperOption",
        "document" => "+DocumentPaperOption",
        "attachments" => "+AttachmentsPaperOption",
        "intrinsic" => "+IntrinsicPaperOption"
    ];

    function __construct(Conf $conf, $args) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        $this->conf = $conf;
        $this->id = (int) $args["id"];
        $this->name = $args["name"];
        if ($this->name === null) {
            $this->name = "<Unknown-{$this->id}>";
        }
        $this->title = get($args, "title");
        if (!$this->title && $this->id > 0) {
            $this->title = $this->name;
        }
        $this->type = get($args, "type");

        if (($x = get_s($args, "json_key"))) {
            $this->_json_key = $this->_search_keyword = $x;
        }
        if (($x = get_s($args, "search_keyword"))) {
            $this->_search_keyword = $x;
        }
        $this->description = get($args, "description");
        $this->description_format = get($args, "description_format");
        $this->required = !!get($args, "required");
        $this->final = !!get($args, "final");
        $this->nonpaper = !!get($args, "nonpaper");

        if ($this->id > 0) {
            $this->formid = "opt" . $this->id;
        } else {
            $this->formid = $this->_json_key;
        }
        $this->readable_formid = get($args, "readable_formid");

        $vis = get($args, "visibility") ? : get($args, "view_type");
        if ($vis !== "rev" && $vis !== "nonblind" && $vis !== "admin")
            $vis = "rev";
        $this->visibility = $vis;

        $disp = get($args, "display");
        if (get($args, "near_submission"))
            $disp = "submission";
        if (get($args, "highlight"))
            $disp = "prominent";
        if ($disp === null)
            $disp = "topics";
        if ($disp === false)
            $disp = "none";
        $this->display = get(self::$display_map, $disp, self::DISP_DEFAULT);
        if ($this->display === self::DISP_DEFAULT)
            $this->display = $this->has_document() ? self::DISP_PROMINENT : self::DISP_TOPICS;

        $p = get($args, "position");
        if ((is_int($p) || is_float($p))
            && ($this->id <= 0 || $p > 0))
            $this->position = $p;
        else
            $this->position = 499;

        $p = get($args, "form_position");
        if ($p === null) {
            if ($this->display === self::DISP_SUBMISSION)
                $p = 1100 + $this->position;
            else if ($this->display === self::DISP_PROMINENT)
                $p = 3100 + $this->position;
            else
                $p = 3600 + $this->position;
        }
        $this->form_position = $p;

        if ($this->display < 0)
            $p = false;
        $this->display_position = get($args, "display_position", $p);
        $this->display_expand = !!get($args, "display_expand");
        $this->display_group = get($args, "display_group");
        if ($this->display_group === null
            && $this->display_position >= 3500
            && $this->display_position < 4000) {
            $this->display_group = "topics";
        }

        if (($x = get($args, "display_space")))
            $this->display_space = (int) $x;

        if (array_key_exists("exists_if", $args))
            $x = $args["exists_if"];
        else
            $x = get($args, "edit_condition"); // XXX
        if ($x !== null && $x !== true) {
            $this->exists_if = $x;
            $this->_exists_search = new PaperSearch($this->conf->site_contact(), $x === false ? "NONE" : $x);
        }

        if (($x = get($args, "editable_if")) !== null && $x !== true) {
            $this->editable_if = $x;
            $this->_editable_search = new PaperSearch($this->conf->site_contact(), $x === false ? "NONE" : $x);
        }
    }

    static function make($args, $conf) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        $callback = get($args, "callback");
        if (!$callback) {
            $callback = get(self::$callback_map, get($args, "type"));
        }
        if (!$callback) {
            $callback = "+UnknownPaperOption";
        }
        if ($callback[0] === "+") {
            $class = substr($callback, 1);
            return new $class($conf, $args);
        } else {
            return call_user_func($callback, $conf, $args);
        }
    }

    static function compare($a, $b) {
        $ap = $a->display_position();
        $ap = $ap !== false ? $ap : PHP_INT_MAX;
        $bp = $b->display_position();
        $bp = $bp !== false ? $bp : PHP_INT_MAX;
        if ($ap !== $bp)
            return $ap < $bp ? -1 : 1;
        else
            return Conf::xt_position_compare($a, $b);
    }

    static function make_readable_formid($s) {
        $s = strtolower(preg_replace('{[^A-Za-z0-9]+}', "-", UnicodeHelper::deaccent($s)));
        if ($s[strlen($s) - 1] === "-")
            $s = substr($s, 0, -1);
        if (!preg_match('{\A(?:title|paper|submission|final|authors|blind|contacts|abstract|topics|pcconf|collaborators|submit|paperform|htctl.*|fold.*|pcc\d+|body.*|tracker.*|msg.*|header.*|footer.*|quicklink.*|-|)\z}', $s))
            return $s;
        else
            return "field-" . $s;
    }

    function fixed() {
        return $this->id >= self::MINFIXEDID;
    }

    function title($context = null) {
        if ($this->title)
            return $this->title;
        else if ($context === null)
            return $this->conf->_ci("field", $this->formid);
        else
            return $this->conf->_ci("field", $this->formid, null, $context);
    }
    function title_html($context = null) {
        return htmlspecialchars($this->title($context));
    }
    function plural_title() {
        return $this->title ? : $this->conf->_ci("field/plural", $this->formid);
    }
    function edit_title() {
        return $this->title ? : $this->conf->_ci("field/edit", $this->formid);
    }

    private function abbrev_matcher() {
        if ($this->nonpaper)
            return $this->conf->paper_opts->nonpaper_abbrev_matcher();
        else
            return $this->conf->abbrev_matcher();
    }
    function abbreviations_for($name, $data) {
        assert($this === $data);
        return $this->search_keyword();
    }
    function search_keyword() {
        if ($this->_search_keyword === null) {
            $am = $this->abbrev_matcher();
            $aclass = new AbbreviationClass;
            $this->_search_keyword = $am->unique_abbreviation($this->name, $this, $aclass);
            if (!$this->_search_keyword) {
                $aclass->type = AbbreviationClass::TYPE_LOWERDASH;
                $this->_search_keyword = $am->unique_abbreviation($this->name, $this, $aclass);
            }
            if (!$this->_search_keyword)
                $this->_search_keyword = $this->formid;
        }
        return $this->_search_keyword;
    }
    function field_key() {
        return $this->formid;
    }
    function readable_formid() {
        if ($this->readable_formid === null) {
            $used = [];
            foreach ($this->conf->paper_opts->option_list() as $o) {
                if ($o->readable_formid !== null)
                    $used[$o->readable_formid] = true;
            }
            foreach ($this->conf->paper_opts->option_list() as $o) {
                if ($o->readable_formid === null && $o->id > 0) {
                    $s = self::make_readable_formid($o->title);
                    $o->readable_formid = isset($used[$s]) ? $o->formid : $s;
                    $used[$o->readable_formid] = true;
                }
            }
        }
        return $this->readable_formid;
    }
    function json_key() {
        if ($this->_json_key === null) {
            $am = $this->abbrev_matcher();
            $aclass = new AbbreviationClass(AbbreviationClass::TYPE_LOWERDASH, 4);
            $this->_json_key = $am->unique_abbreviation($this->name, $this, $aclass);
            if (!$this->_json_key)
                $this->_json_key = $this->formid;
        }
        return $this->_json_key;
    }
    function dtype_name() {
        return $this->json_key();
    }

    function display() {
        return $this->display;
    }
    function form_position() {
        return $this->form_position;
    }
    function display_position() {
        return $this->display_position;
    }

    function exists_condition() {
        return $this->exists_if;
    }
    function test_exists(PaperInfo $prow) {
        return !$this->_exists_search || $this->_exists_search->test($prow);
    }
    function compile_exists_condition(PaperInfo $prow) {
        assert($this->_exists_search !== null);
        return $this->_exists_search->term()->compile_condition($prow, $this->_exists_search);
    }

    function editable_condition() {
        return $this->editable_if;
    }
    function test_editable(PaperInfo $prow) {
        return !$this->_editable_search || $this->_editable_search->test($prow);
    }

    function has_selector() {
        return false;
    }

    function is_document() {
        return false;
    }
    function has_document() {
        return false;
    }
    function allow_empty_document() {
        return false;
    }
    function mimetypes() {
        return null;
    }
    function has_attachments() {
        return false;
    }

    function takes_multiple() {
        return false;
    }

    function refresh_documents(PaperOptionValue $ov) {
    }

    function attachment(PaperOptionValue $ov, $name) {
        return null;
    }

    function expand_values(&$values, &$data_array) {
    }

    function display_name() {
        if (!self::$display_rmap)
            self::$display_rmap = array_flip(self::$display_map);
        return self::$display_rmap[$this->display];
    }

    function unparse() {
        $j = (object) array("id" => (int) $this->id,
                            "name" => $this->name,
                            "type" => $this->type,
                            "position" => (int) $this->position);
        if ($this->description !== null)
            $j->description = $this->description;
        if ($this->description_format !== null)
            $j->description_format = $this->description_format;
        if ($this->final)
            $j->final = true;
        $j->display = $this->display_name();
        if ($this->visibility !== "rev")
            $j->visibility = $this->visibility;
        if ($this->display_space)
            $j->display_space = $this->display_space;
        if ($this->exists_if !== null)
            $j->exists_if = $this->exists_if;
        if ($this->editable_if !== null)
            $j->editable_if = $this->editable_if;
        if ($this->required)
            $j->required = true;
        return $j;
    }

    function parse_search($oms) {
        if (!$oms->quoted && $oms->compar === "=") {
            if ($oms->vword === "" || strcasecmp($oms->vword, "yes") === 0) {
                $oms->os[] = new OptionMatcher($this, "!=", null);
                return true;
            } else if (strcasecmp($oms->vword, "no") === 0) {
                $oms->os[] = new OptionMatcher($this, "=", null);
                return true;
            }
        }
        return false;
    }
    function example_searches() {
        return ["has" => ["has:{$this->search_keyword()}", $this],
                "yes" => ["{$this->search_keyword()}:any", $this]];
    }
    function add_search_completion(&$res) {
        array_push($res, "has:{$this->search_keyword()}",
                   "opt:{$this->search_keyword()}");
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return false;
    }

    function value_present(PaperOptionValue $ov) {
        return !!$ov->value;
    }
    function value_compare($av, $bv) {
        return 0;
    }

    static function basic_value_compare($av, $bv) {
        $av = $av ? $av->value : null;
        $bv = $bv ? $bv->value : null;
        if ($av === $bv)
            return 0;
        else if ($av === null || $bv === null)
            return $av === null ? -1 : 1;
        else
            return $av < $bv ? -1 : ($av > $bv ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
    }

    function echo_editable_text_html(PaperOptionValue $ov, $reqv, PaperTable $pt,
                                     $extra = []) {
        $default_value = null;
        if ($reqv === null) {
            $reqv = (string) $ov->data();
        } else if ($reqv !== $ov->data()
                   && trim(cleannl($reqv)) !== trim($ov->data())) {
            $default_value = $ov->data();
        }
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">';
        if (($fi = $ov->prow->edit_format())
            && !get($extra, "no_format_description"))
            echo $fi->description_preview_html();
        echo Ht::textarea($this->formid, $reqv, [
                "id" => $this->readable_formid(),
                "class" => $pt->control_class($this->formid, "papertext need-autogrow"),
                "rows" => max($this->display_space, 1),
                "cols" => 60,
                "spellcheck" => get($extra, "no_spellcheck") ? null : "true",
                "data-default-value" => $default_value
            ]),
            $pt->messages_at($this->formid),
            "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        return null;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $prow) {
        return $this->parse_request_display($qreq, $user, $prow);
    }
    function parse_request_display(Qrequest $qreq, Contact $user, $prow) {
        return null;
    }

    function validate_document(DocumentInfo $doc) {
        return true;
    }

    function store_json($pj, PaperStatus $ps) {
        return null;
    }
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

    function list_display($isrow) {
        return false;
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
    }

    function format_spec() {
        return false;
    }
}

class CheckboxPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function value_compare($av, $bv) {
        return ($bv && $bv->value ? 1 : 0) - ($av && $av->value ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = !!($reqv === null ? $ov->value : $reqv);
        $cb = Ht::checkbox($this->formid, 1, $reqv, ["id" => $this->readable_formid(), "data-default-checked" => !!$ov->value]);
        $pt->echo_editable_option_papt($this,
            '<span class="checkc">' . $cb . "</span>" . $pt->edit_title_html($this),
            ["for" => "checkbox", "tclass" => "ui js-click-child"]);
        echo $pt->messages_at($this->formid), "</div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        return $ov->value ? true : false;
    }

    function parse_request_display(Qrequest $qreq, Contact $user, $prow) {
        return $qreq[$this->formid] > 0;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_bool($pj) || $pj === null)
            return $pj ? 1 : null;
        $ps->error_at_option($this, "Option should be “true” or “false”.");
    }

    function list_display($isrow) {
        return $isrow ? true : ["column" => true, "className" => "pl_option plc"];
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        if ($fr->for_page() && $ov->value) {
            $fr->title = "";
            $fr->set_html('✓ <span class="pavfn">' . $this->title_html() . '</span>');
        } else {
            $fr->set_bool(!!$ov->value);
        }
    }
}

class SelectorPaperOption extends PaperOption {
    private $selector;
    private $_selector_am;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->selector = get($args, "selector");
    }

    function has_selector() {
        return true;
    }
    function selector_options() {
        return $this->selector;
    }
    function set_selector_options($selector) {
        $this->selector = $selector;
    }
    function selector_option_search($idx) {
        if ($idx <= 0)
            return $this->search_keyword() . ":none";
        else if ($idx > count($this->selector))
            return false;
        else {
            $am = $this->selector_abbrev_matcher();
            if (($q = $am->unique_abbreviation($this->selector[$idx - 1], $idx, new AbbreviationClass(AbbreviationClass::TYPE_LOWERDASH, 1))))
                return $this->search_keyword() . ":" . $q;
            else
                return false;
        }
    }
    function selector_abbrev_matcher() {
        if (!$this->_selector_am) {
            $this->_selector_am = new AbbreviationMatcher;
            foreach ($this->selector as $id => $name)
                $this->_selector_am->add($name, $id + 1);
            if (!$this->required)
                $this->_selector_am->add("none", 0);
        }
        return $this->_selector_am;
    }

    function unparse() {
        $j = parent::unparse();
        $j->selector = $this->selector;
        return $j;
    }

    function parse_search($oms) {
        $vs = $this->selector_abbrev_matcher()->find_all($oms->vword);
        if (empty($vs)) {
            $oms->warnings[] = "“" . $this->title_html() . "” search “" . htmlspecialchars($oms->vword) . "” matches no options.";
            return false;
        } else if (count($vs) === 1) {
            $oms->os[] = new OptionMatcher($this, $oms->compar, $vs[0]);
            return true;
        } else if ($oms->compar === "=" || $oms->compar === "!=") {
            $oms->os[] = new OptionMatcher($this, $oms->compar, $vs);
            return true;
        } else {
            $oms->warnings[] = "“" . $this->title_html() . "” search “" . htmlspecialchars($oms->vword) . "” matches more than one option.";
            return false;
        }
    }
    function example_searches() {
        $x = parent::example_searches();
        if (($search = $this->selector_option_search(2)))
            $x["selector"] = [$search, $this, $this->selector[1]];
        return $x;
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return $o instanceof SelectorPaperOption;
    }

    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = $reqv === null ? $ov->value : $reqv;
        $reqv = $reqv && isset($this->selector[$reqv - 1]) ? $reqv : 0;
        $pt->echo_editable_option_papt($this, null,
            $this->type === "selector"
            ? ["for" => $this->readable_formid()]
            : ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev">';
        if ($this->type === "selector") {
            $sel = [];
            if (!$ov->value)
                $sel[0] = "(Select one)";
            foreach ($this->selector as $val => $text)
                $sel[$val + 1] = $text;
            echo Ht::select($this->formid, $sel, $reqv,
                ["id" => $this->readable_formid(),
                 "data-default-value" => $ov->value]);
        } else {
            foreach ($this->selector as $val => $text) {
                echo '<div class="checki"><label><span class="checkc">',
                    Ht::radio($this->formid, $val + 1, $val + 1 == $reqv,
                        ["data-default-checked" => $val + 1 == $ov->value]),
                    ' </span>', htmlspecialchars($text), '</label></div>';
            }
        }
        echo $pt->messages_at($this->formid), "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        return get($this->selector, $ov->value - 1, null);
    }

    function parse_request_display(Qrequest $qreq, Contact $user, $prow) {
        $v = trim((string) $qreq[$this->formid]);
        if ($v === "" || $v === "0")
            return null;
        else if (ctype_digit($v)) {
            $iv = intval($v);
            if (isset($this->selector[$iv - 1]))
                return $iv;
        }
        return $v;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_string($pj)
            && ($v = array_search($pj, $this->selector)) !== false)
            $pj = $v + 1;
        if ((is_int($pj) && isset($this->selector[$pj - 1])) || $pj === null)
            return $pj;
        $ps->error_at_option($this, "Option doesn’t match any of the selectors.");
    }

    function list_display($isrow) {
        return true;
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        $fr->set_text(get($this->selector, $ov->value - 1, ""));
    }
}

class DocumentPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function is_document() {
        return true;
    }

    function has_document() {
        return true;
    }

    function mimetypes() {
        if ($this->type === "pdf" || $this->id <= 0)
            return [Mimetype::lookup(".pdf")];
        else if ($this->type === "slides")
            return [Mimetype::lookup(".pdf"), Mimetype::lookup(".ppt"), Mimetype::lookup(".pptx")];
        else if ($this->type === "video")
            return [Mimetype::lookup(".mp4"), Mimetype::lookup(".avi")];
        else
            return null;
    }

    function change_type(PaperOption $o, $upgrade, $change_values) {
        return $o instanceof DocumentPaperOption;
    }

    function value_compare($av, $bv) {
        return ($av && $av->value ? 1 : 0) - ($bv && $bv->value ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        // XXXX this is super gross
        if ($this->id > 0 && $ov->value)
            $docid = $ov->value;
        else if ($this->id == DTYPE_SUBMISSION
                 && $ov->prow->paperStorageId > 1)
            $docid = $ov->prow->paperStorageId;
        else if ($this->id == DTYPE_FINAL
                 && $ov->prow->finalPaperStorageId > 0)
            $docid = $ov->prow->finalPaperStorageId;
        else
            $docid = 0;
        $pt->echo_editable_document($this, $docid);
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        if (!$ov->value) {
            return null;
        } else if (($doc = $ps->document_to_json($this->id, $ov->value))) {
            return $doc;
        } else {
            return false;
        }
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $prow) {
        if ($qreq->has_file($this->formid)) {
            $pid = $prow ? $prow->paperId : -1;
            return DocumentInfo::make_file_upload($pid, $this->id, $qreq->file($this->formid), $this->conf);
        } else if ($qreq["remove_{$this->formid}"])
            return null;
        else
            return $opt_pj;
    }

    function validate_document(DocumentInfo $doc) {
        $mimetypes = $this->mimetypes();
        if (empty($mimetypes))
            return true;
        for ($i = 0; $i < count($mimetypes); ++$i)
            if ($mimetypes[$i]->mimetype === $doc->mimetype)
                return true;
        $desc = htmlspecialchars(Mimetype::description($mimetypes));
        $e = "I only accept $desc files."
            . " (Your file has MIME type “" . htmlspecialchars($doc->mimetype) . "” and "
            . htmlspecialchars($doc->content_text_signature())
            . ".)<br>Please convert your file to "
            . (count($mimetypes) > 3 ? "a supported type" : $desc)
            . " and try again.";
        $doc->add_error_html($e);
        return false;
    }

    function store_json($pj, PaperStatus $ps) {
        if ($pj !== null) {
            $xpj = $ps->upload_document($pj, $this);
            return $xpj ? $xpj->paperStorageId : null;
        }
    }

    function list_display($isrow) {
        return true;
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        if ($this->id <= 0 && $fr->for_page()) {
            $fr->table->render_submission($fr, $this);
        } else if (($d = $ov->document(0))) {
            if ($fr->want_text()) {
                $fr->set_text($d->filename);
            } else if ($fr->for_page()) {
                $fr->title = "";
                $dif = 0;
                if ($this->display_position() >= 2000)
                    $dif = DocumentInfo::L_SMALL;
                $fr->set_html($d->link_html('<span class="pavfn">' . $this->title_html() . '</span>', $dif));
            } else {
                $fr->set_html($d->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE));
            }
        }
    }

    function format_spec() {
        $speckey = "sub_banal";
        if ($this->id)
            $speckey .= ($this->id < 0 ? "_m" : "_") . abs($this->id);
        $fspec = new FormatSpec;
        if (($xspec = $this->conf->opt($speckey)))
            $fspec->merge($xspec, $this->conf->opt_timestamp());
        if (($spects = $this->conf->setting($speckey)) > 0)
            $fspec->merge($this->conf->setting_data($speckey, ""), $spects);
        else if ($spects < 0)
            $fspec->clear_banal();
        if (!$fspec->is_banal_empty())
            $fspec->merge_banal();
        return $fspec;
    }
}

class NumericPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function parse_search($oms) {
        if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oms->vword, $m)) {
            $oms->os[] = new OptionMatcher($this, $oms->compar, intval($m[1]));
            return true;
        } else if (parent::parse_search($oms)) {
            return true;
        } else {
            $oms->warnings[] = "“" . $this->title_html() . "” search “" . htmlspecialchars($oms->vword) . "” is not an integer.";
            return false;
        }
    }
    function example_searches() {
        $x = parent::example_searches();
        $x["numeric"] = array("{$this->search_keyword()}:>100", $this);
        return $x;
    }

    function value_present(PaperOptionValue $ov) {
        return $ov->value !== null;
    }
    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = (string) ($reqv === null ? $ov->value : $reqv);
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry($this->formid, $reqv, ["id" => $this->readable_formid(), "size" => 8, "class" => "js-autosubmit" . $pt->has_error_class($this->formid), "data-default-value" => $ov->value]),
            $pt->messages_at($this->formid),
            "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        return $ov->value;
    }

    function parse_request_display(Qrequest $qreq, Contact $user, $prow) {
        $v = trim((string) $qreq[$this->formid]);
        if ($v === "")
            return null;
        else if (is_numeric($v)) {
            $iv = intval($v);
            if ((float) $iv === floatval($v))
                return $iv;
        }
        return $v;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_int($pj))
            return $pj;
        else if ($pj === null || $pj === false)
            return null;
        $ps->error_at_option($this, "Option should be an integer.");
    }

    function list_display($isrow) {
        return $isrow ? true : ["column" => true, "className" => "pl_option plrd"];
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        if ($ov->value !== null) {
            $fr->set_text($ov->value);
        }
    }
}

class TextPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }
    static function expand($name, $user, $fxt, $m) {
        $xt = clone $fxt;
        unset($xt->match);
        $xt->name = $name;
        $xt->display_space = +$m[1];
        $xt->title = "Multiline text ({$m[1]} lines)";
        return $xt;
    }

    function parse_search($oms) {
        if ($oms->compar === "=" || $oms->compar === "!=") {
            $oms->os[] = new OptionMatcher($this, $oms->compar, $oms->vword, "text");
            return true;
        } else {
            $oms->warnings[] = "“" . $this->title_html() . "” search “" . htmlspecialchars($oms->compar . $oms->vword) . "” too complex.";
            return false;
        }
    }

    function value_present(PaperOptionValue $ov) {
        return (string) $ov->data() !== "";
    }
    function value_compare($av, $bv) {
        $av = $av ? (string) $av->data() : "";
        $bv = $bv ? (string) $bv->data() : "";
        if ($av !== "" && $bv !== "")
            return strcasecmp($av, $bv);
        else
            return ($bv !== "" ? 1 : 0) - ($av !== "" ? 1 : 0);
    }


    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $this->echo_editable_text_html($ov, $reqv, $pt);
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        $x = $ov->data();
        return $x !== "" ? $x : null;
    }

    function parse_request_display(Qrequest $qreq, Contact $user, $prow) {
        $x = trim((string) $qreq[$this->formid]);
        return $x !== "" ? $x : null;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_string($pj))
            return $pj === "" ? null : [1, convert_to_utf8($pj)];
        else if ($pj !== null)
            $ps->error_at_option($this, "Option should be a string.");
    }

    function list_display($isrow) {
        return ["row" => true, "className" => "pl_textoption"];
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        $d = $ov->data();
        if ($d !== null && $d !== "") {
            $fr->value = $d;
            $fr->value_format = $ov->prow->format_of($d);
            $fr->value_long = true;
        }
    }
}

class AttachmentsPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function has_document() {
        return true;
    }

    function has_attachments() {
        return true;
    }

    function takes_multiple() {
        return true;
    }

    function attachment(PaperOptionValue $ov, $name) {
        foreach ($ov->documents() as $xdoc)
            if ($xdoc->unique_filename == $name)
                return $xdoc;
        return null;
    }

    function expand_values(&$values, &$data_array) {
        $j = null;
        foreach ($data_array as $d)
            if (str_starts_with($d, "{"))
                $j = json_decode($d);
        if ($j && isset($j->all_dids)) {
            $values = $j->all_dids;
            $data_array = array_fill(0, count($values), null);
        } else
            array_multisort($data_array, SORT_NUMERIC, $values);
    }

    function parse_search($oms) {
        if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oms->vword, $m)) {
            $oms->os[] = new OptionMatcher($this, $oms->compar, $m[1], "attachment-count");
            return true;
        } else if (parent::parse_search($oms)) {
            return true;
        } else if ($oms->compar === "=" || $oms->compar === "!=") {
            $oms->os[] = new OptionMatcher($this, $oms->compar, $oms->vword, "attachment-name");
            return true;
        } else {
            $oms->warnings[] = "“" . $this->title_html() . "” search “" . htmlspecialchars($oms->compar . $oms->vword) . "” too complex.";
            return false;
        }
    }
    function example_searches() {
        $x = parent::example_searches();
        $x["attachment-count"] = array("{$this->search_keyword()}:>2", $this);
        $x["attachment-filename"] = array("{$this->search_keyword()}:*.gif", $this);
        return $x;
    }

    function value_compare($av, $bv) {
        return ($av && $av->value_count() ? 1 : 0) - ($bv && $bv->value_count() ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $pt->echo_editable_option_papt($this, $this->title_html() . ' <span class="n">(max ' . ini_get("upload_max_filesize") . "B per file)</span>", ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev has-editable-attachments" data-document-prefix="', $this->formid, '" id="', $this->formid, '_attachments">';
        foreach ($ov->documents() as $i => $doc) {
            $oname = "{$this->formid}_{$doc->paperStorageId}_{$i}";
            echo '<div class="has-document" data-document-name="', $oname, '">',
                '<div class="document-file">',
                    $doc->link_html(htmlspecialchars($doc->unique_filename)),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc)))
                echo $stamps;
            echo '</div><div class="document-actions">',
                    Ht::link("Delete", "", ["class" => "ui js-remove-document document-action"]),
                '</div></div>';
        }
        echo '</div>', Ht::button("Add attachment", ["class" => "ui js-add-attachment", "data-editable-attachments" => "{$this->formid}_attachments"]),
            $pt->messages_at($this->formid),
            "</div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps) {
        $attachments = [];
        foreach ($ov->documents() as $doc)
            if (($doc = $ps->document_to_json($this->id, $doc)))
                $attachments[] = $doc;
        return empty($attachments) ? null : $attachments;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $prow) {
        $pid = $prow ? $prow->paperId : -1;
        $attachments = $opt_pj ? : [];
        for ($i = count($attachments) - 1; $i >= 0; --$i)
            if (isset($attachments[$i]->docid)) {
                $pfx = "remove_{$this->formid}_{$attachments[$i]->docid}";
                if ($qreq["{$pfx}_{$i}"] || $qreq[$pfx] /* XXX backwards compat */)
                    array_splice($attachments, $i, 1);
            }
        for ($i = 1; isset($qreq["has_{$this->formid}_new_$i"]); ++$i)
            if (($f = $qreq->file("{$this->formid}_new_$i")))
                $attachments[] = DocumentInfo::make_file_upload($pid, $this->id, $f, $this->conf);
        return empty($attachments) ? null : $attachments;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_object($pj))
            $pj = [$pj];
        $result = [];
        foreach ($pj as $docj) {
            if (($xdocj = $ps->upload_document($docj, $this)))
                $result[] = $xdocj->paperStorageId;
        }
        if (count($result) >= 2) {
            // Duplicate the document IDs in the first option’s sort data.
            // This is so (1) the link from option -> PaperStorage is visible
            // directly via PaperOption.value, (2) we can still support
            // duplicate uploads.
            $uids = array_unique($result, SORT_NUMERIC);
            $uids[0] = [$uids[0], json_encode(["all_dids" => $result])];
            return $uids;
        } else
            return $result;
    }

    function list_display($isrow) {
        return true;
    }

    function render(FieldRender $fr, PaperOptionValue $ov) {
        $ts = [];
        foreach ($ov->documents() as $d) {
            if ($fr->want_text()) {
                $ts[] = $d->unique_filename;
            } else {
                $linkname = htmlspecialchars($d->unique_filename);
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
                if ($fr->table && $fr->table->user->view_option_state($ov->prow, $this) === 1)
                    $v .= ' fx8';
                $fr->value = $v . '">' . $fr->value . '</div>';
            }
        } else if ($fr->verbose()) {
            $fr->set_text("None");
        }
    }
}

class IntrinsicPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        if ($this->id === PaperOption::TITLEID) {
            $this->echo_editable_text_html($ov, $reqv, $pt, ["no_format_description" => true]);
        } else if ($this->id === PaperOption::ABSTRACTID) {
            if ((int) $this->conf->opt("noAbstract") !== 1) {
                $this->echo_editable_text_html($ov, $reqv, $pt);
            }
        } else if ($this->id === PaperOption::AUTHORSID) {
            $pt->echo_editable_authors($this);
        } else if ($this->id === -1002) {
            $pt->echo_editable_anonymity($this);
        } else if ($this->id === PaperOption::CONTACTSID) {
            $pt->echo_editable_contact_author($this);
        } else if ($this->id === -1005) {
            $pt->echo_editable_topics($this);
        } else if ($this->id === PaperOption::PCCONFID) {
            $pt->echo_editable_pc_conflicts($this);
        } else if ($this->id === PaperOption::COLLABORATORSID) {
            if ($this->conf->setting("sub_collab")
                && ($pt->editable !== "f" || $pt->user->can_administer($pt->prow))) {
                $this->echo_editable_text_html($ov, $reqv, $pt, ["no_format_description" => true, "no_spellcheck" => true]);
            }
        }
    }
    function render(FieldRender $fr, PaperOptionValue $ov) {
        if ($this->id === PaperOption::TITLEID) {
            $fr->value = $ov->prow->title ? : "[No title]";
            $fr->value_format = $ov->prow->title_format();
        } if ($this->id === PaperOption::ABSTRACTID) {
            if ($fr->for_page()) {
                $fr->table->render_abstract($fr, $this);
            } else {
                $text = $ov->prow->abstract;
                if (trim($text) !== "") {
                    $fr->value = $text;
                    $fr->value_format = $ov->abstract_format();
                } else if (!$this->conf->opt("noAbstract")
                           && $fr->verbose()) {
                    $fr->set_text("[No abstract]");
                }
            }
        } else if ($this->id === PaperOption::AUTHORSID) {
            $fr->table->render_authors($fr, $this);
        } else if ($this->id === -1005) {
            $fr->table->render_topics($fr, $this);
        } else if ($this->id === -1008) {
            $fr->table->render_submission_version($fr, $this);
        }
    }
}

class UnknownPaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        $args["type"] = "__unknown" . $args["id"] . "__";
        $args["form_position"] = $args["display_position"] = false;
        parent::__construct($conf, $args);
    }

    function takes_multiple() {
        return true;
    }

    function parse_search($oms) {
        return false;
    }
    function example_searches() {
        return [];
    }
    function add_search_completion(&$res) {
    }
}
