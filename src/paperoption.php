<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
        $this->assign($values, $data_array);
    }
    function assign($values, $data_array) {
        $old_values = $this->_values;
        $this->_values = $values;
        $this->_data_array = $data_array;
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
    function documents() {
        assert($this->prow || empty($this->_values));
        assert($this->option->has_document());
        if ($this->_documents === null) {
            $this->_documents = $by_unique_filename = array();
            $docclass = null;
            $this->option->refresh_documents($this);
            foreach ($this->sorted_values() as $docid)
                if ($docid > 1 && ($d = $this->prow->document($this->id, $docid))) {
                    $d->unique_filename = $d->filename;
                    while (get($by_unique_filename, $d->unique_filename)) {
                        if (preg_match('/\A(.*\()(\d+)(\)(?:\.\w+|))\z/', $d->unique_filename, $m))
                            $d->unique_filename = $m[1] . ($m[2] + 1) . $m[3];
                        else if (preg_match('/\A(.*?)(\.\w+|)\z/', $d->unique_filename, $m) && $m[1] !== "")
                            $d->unique_filename = $m[1] . " (1)" . $m[2];
                        else
                            $d->unique_filename .= " (1)";
                    }
                    $by_unique_filename[$d->unique_filename] = true;
                    $this->_documents[] = $d;
                }
        }
        return $this->_documents;
    }
    function document($index) {
        return get($this->documents(), $index);
    }
    function document_content($index) {
        if (($doc = $this->document($index))
            && $doc->docclass->load($doc, false)
            && ($content = Filer::content($doc)))
            return $content;
        return false;
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
            $this->prow->_assign_option_value($this);
        return $this->_values;
    }
    function data() {
        if ($this->_data_array === null)
            $this->prow->_assign_option_value($this);
        return $this->_data;
    }
    function invalidate() {
        $this->prow->_reload_option_value($this);
    }
}

class PaperOptionList {
    private $conf;
    private $jlist;
    private $jmap = [];
    private $list;
    private $nonfinal_list;
    private $nonpaper_am;
    private $osubmission;
    private $ofinal;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->osubmission = new DocumentPaperOption(["id" => DTYPE_SUBMISSION, "name" => "Submission", "message_name" => "submission", "json_key" => "paper", "type" => null, "position" => 0], $this->conf);
        $this->ofinal = new DocumentPaperOption(["id" => DTYPE_FINAL, "name" => "Final version", "message_name" => "final version", "json_key" => "final", "type" => null, "final" => true, "position" => 0], $this->conf);
    }

    function _add_json($oj, $fixed) {
        if (is_string($oj->id) && is_numeric($oj->id))
            $oj->id = intval($oj->id);
        if (is_int($oj->id) && $oj->id > 0 && !isset($this->jlist[$oj->id])
            && ($oj->id >= PaperOption::MINFIXEDID) === $fixed
            && isset($oj->name) && is_string($oj->name)) {
            if (!isset($oj->enable_if) || $this->conf->xt_enabled($oj, null))
                $this->jlist[$oj->id] = $oj;
            return true;
        } else
            return false;
    }

    private function option_json_list() {
        if ($this->jlist === null) {
            $this->jlist = $this->jmap = [];
            if (($olist = $this->conf->setting_json("options")))
                expand_json_includes_callback($olist, [$this, "_add_json"], false);
            if (($olist = $this->conf->opt("fixedOptions")))
                expand_json_includes_callback($olist, [$this, "_add_json"], true);
            uasort($this->jlist, ["PaperOption", "compare"]);
        }
        return $this->jlist;
    }

    function option_ids() {
        $m = [];
        foreach ($this->option_json_list() as $id => $oj)
            if (!get($oj, "nonpaper"))
                $m[] = $id;
        return $m;
    }

    function get($id, $force = false) {
        if ($id <= 0) {
            if ($id == DTYPE_SUBMISSION)
                return $this->osubmission;
            else if ($id == DTYPE_FINAL)
                return $this->ofinal;
            else
                return null;
        } else if (array_key_exists($id, $this->jmap))
            $o = $this->jmap[$id];
        else {
            $o = null;
            if (($oj = get($this->option_json_list(), $id)))
                $o = PaperOption::make($oj, $this->conf);
            if ($o && isset($o->enable_if) && !$this->conf->xt_enabled($o))
                $o = null;
            $this->jmap[$id] = $o;
        }
        if (!$o && $force)
            $o = $this->jmap[$id] = new UnknownPaperOption($id, $this->conf);
        return $o;
    }

    function option_list() {
        if ($this->list === null) {
            $this->list = [];
            foreach ($this->option_json_list() as $id => $oj)
                if (!get($oj, "nonpaper")
                    && ($o = $this->get($id))) {
                    assert(!$o->nonpaper);
                    $this->list[$id] = $o;
                }
        }
        return $this->list;
    }

    function nonfixed_option_list() {
        return array_filter($this->option_list(), function ($o) {
            return $o->id < PaperOption::MINFIXEDID;
        });
    }

    function nonfinal_option_list() {
        if ($this->nonfinal_list === null) {
            $this->nonfinal_list = [];
            foreach ($this->option_json_list() as $id => $oj)
                if (!get($oj, "nonpaper")
                    && !get($oj, "final")
                    && ($o = $this->get($id))
                    && !$o->final) {
                    assert(!$o->nonpaper);
                    $this->nonfinal_list[$id] = $o;
                }
        }
        return $this->nonfinal_list;
    }

    function invalidate_option_list() {
        $this->jlist = $this->list = $this->nonfinal_list = $this->nonpaper_am = null;
        $this->jmap = [];
    }

    function count_option_list() {
        return count($this->option_json_list());
    }

    function find_all($name) {
        $iname = strtolower($name);
        if ($iname === (string) DTYPE_SUBMISSION
            || $iname === "paper"
            || $iname === "submission")
            return array(DTYPE_SUBMISSION => $this->get(DTYPE_SUBMISSION));
        else if ($iname === (string) DTYPE_FINAL
                 || $iname === "final")
            return array(DTYPE_FINAL => $this->get(DTYPE_FINAL));
        if ($iname === "" || $iname === "none")
            return array();
        if ($iname === "any")
            return $this->option_list();
        if (substr($iname, 0, 4) === "opt-")
            $name = substr($name, 4);
        $omap = [];
        foreach ($this->conf->find_all_fields($name, Conf::FSRCH_OPTION) as $o)
            $omap[$o->id] = $o;
        return $omap;
    }

    function find($name, $nonpaper = false) {
        if ($nonpaper)
            return $this->find_nonpaper($name);
        else {
            $omap = $this->find_all($name);
            reset($omap);
            return count($omap) == 1 ? current($omap) : null;
        }
    }

    function nonpaper_abbrev_matcher() {
        // Nonpaper options aren't stored in the main abbrevmatcher; put them
        // in their own.
        if (!$this->nonpaper_am) {
            $this->nonpaper_am = new AbbreviationMatcher;
            foreach ($this->option_json_list() as $id => $oj)
                if (get($oj, "nonpaper")
                    && ($o = $this->get($id))) {
                    assert($o->nonpaper);
                    $this->nonpaper_am->add($o->name, $o);
                    $this->nonpaper_am->add("opt$o->id", $o);
                }
        }
        return $this->nonpaper_am;
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

    function list_subform_options(PaperOption $o = null) {
        $factory_classes = array_flip(PaperOption::factory_classes());
        foreach ($this->option_json_list() as $x)
            if (isset($x->factory_class))
                $factory_classes[$x->factory_class] = true;
        $options = [];
        foreach ($factory_classes as $f => $x)
            $f::list_subform_options($options, $o);
        usort($options, function ($a, $b) { return $a[0] - $b[0]; });
        return $options;
    }
}

class PaperOption implements Abbreviator {
    const MINFIXEDID = 1000000;

    public $id;
    public $conf;
    public $name;
    public $message_name;
    public $type; // checkbox, selector, radio, numeric, text,
                  // pdf, slides, video, attachments, ...
    private $_json_key;
    private $_search_keyword;
    public $description;
    public $position;
    public $final;
    public $nonpaper;
    public $visibility; // "rev", "nonblind", "admin"
    private $display;
    public $display_space;
    public $selector;
    private $form_priority;
    public $enable_if; // public for PaperOptionList

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

    static private $factory_class_map = [
        "checkbox" => "CheckboxPaperOption",
        "radio" => "SelectorPaperOption", "selector" => "SelectorPaperOption",
        "numeric" => "NumericPaperOption",
        "text" => "TextPaperOption",
        "pdf" => "DocumentPaperOption", "slides" => "DocumentPaperOption", "video" => "DocumentPaperOption",
        "document" => "DocumentPaperOption", "attachments" => "AttachmentsPaperOption"
    ];

    function __construct($args, $conf) {
        global $Conf;
        if (is_object($args))
            $args = get_object_vars($args);
        $this->id = (int) $args["id"];
        $this->conf = $conf ? : $Conf;
        $this->name = $args["name"];
        $this->message_name = get($args, "message_name", $this->name);
        $this->type = $args["type"];

        if (($x = get_s($args, "json_key")))
            $this->_json_key = $this->_search_keyword = $x;
        if (($x = get_s($args, "search_keyword")))
            $this->_search_keyword = $x;
        $this->description = get_s($args, "description");
        $p = get($args, "position");
        if ((is_int($p) || is_float($p)) && ($this->id <= 0 || $p > 0))
            $this->position = $p;
        else
            $this->position = 999;
        $this->final = !!get($args, "final");
        $this->nonpaper = !!get($args, "nonpaper");

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
        $this->form_priority = get_i($args, "form_priority");
        $this->enable_if = get($args, "enable_if");

        if (($x = get($args, "display_space")))
            $this->display_space = (int) $x;
        $this->selector = get($args, "selector");
    }

    static function make($args, $conf) {
        if (is_object($args))
            $args = get_object_vars($args);
        if (($factory = get($args, "factory")))
            return call_user_func($factory, $args, $conf);
        $fclass = get($args, "factory_class");
        $fclass = $fclass ? : get(self::$factory_class_map, get($args, "type"));
        $fclass = $fclass ? : "PaperOption";
        return new $fclass($args, $conf);
    }

    static function compare($a, $b) {
        $ap = isset($a->position) ? (float) $a->position : 99999;
        $bp = isset($b->position) ? (float) $b->position : 99999;
        if ($ap != $bp)
            return $ap < $bp ? -1 : 1;
        else
            return $a->id - $b->id;
    }

    static function type_has_selector($type) {
        return $type === "radio" || $type === "selector";
    }

    function fixed() {
        return $this->id >= self::MINFIXEDID;
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
                $this->_search_keyword = "opt{$this->id}";
        }
        return $this->_search_keyword;
    }
    function field_key() {
        return $this->id <= 0 ? $this->_json_key : "opt" . $this->id;
    }
    function json_key() {
        if ($this->_json_key === null) {
            $am = $this->abbrev_matcher();
            $aclass = new AbbreviationClass;
            $aclass->type = AbbreviationClass::TYPE_LOWERDASH;
            $aclass->nwords = 4;
            $this->_json_key = $am->unique_abbreviation($this->name, $this, $aclass);
            if (!$this->_json_key)
                $this->_json_key = "opt{$this->id}";
        }
        return $this->_json_key;
    }
    function dtype_name() {
        return $this->json_key();
    }

    function display() {
        if ($this->display === self::DISP_DEFAULT)
            return $this->has_document() ? self::DISP_PROMINENT : self::DISP_TOPICS;
        else
            return $this->display;
    }

    function form_priority() {
        if ($this->form_priority)
            return $this->form_priority;
        else if ($this->display == self::DISP_SUBMISSION)
            return 15000 + $this->position;
        else
            return 50000 + $this->position;
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

    static function list_subform_options(&$options, PaperOption $o = null) {
    }

    static function factory_classes() {
        return array_values(array_unique(self::$factory_class_map));
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
        if ($this->description)
            $j->description = $this->description;
        if ($this->final)
            $j->final = true;
        $j->display = $this->display_name();
        if ($this->visibility !== "rev")
            $j->visibility = $this->visibility;
        if ($this->display_space)
            $j->display_space = $this->display_space;
        if ($this->selector)
            $j->selector = $this->selector;
        return $j;
    }

    function example_searches() {
        return ["has" => ["has:{$this->search_keyword()}", $this],
                "yes" => ["{$this->search_keyword()}:yes", $this]];
    }

    function add_search_completion(&$res) {
        array_push($res, "has:{$this->search_keyword()}",
                   "opt:{$this->search_keyword()}");
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

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return null;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        return null;
    }

    function validate_document(DocumentInfo $doc) {
        return true;
    }

    function store_json($pj, PaperStatus $ps) {
        return null;
    }

    function list_display($isrow) {
        return false;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        return "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        return "";
    }

    const PAGE_HTML_DATA = 0;
    const PAGE_HTML_NAME = 1;
    const PAGE_HTML_FULL = 2;
    function unparse_page_html(PaperInfo $row, PaperOptionValue $ov) {
        $x = $this->unparse_page_html_data($row, $ov);
        return (string) $x !== "" ? [self::PAGE_HTML_DATA, $x] : false;
    }
    function unparse_page_html_data(PaperInfo $row, PaperOptionValue $ov) {
        return "";
    }

    function format_spec() {
        return false;
    }
}

class CheckboxPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [100, "checkbox", "Checkbox"];
    }

    function value_compare($av, $bv) {
        return ($bv && $bv->value ? 1 : 0) - ($av && $av->value ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = !!($reqv === null ? $ov->value : $reqv);
        $cb = Ht::checkbox("opt{$this->id}", 1, $reqv, ["data-default-checked" => !!$ov->value]);
        $pt->echo_editable_option_papt($this, $cb . "&nbsp;" . Ht::label(htmlspecialchars($this->name)));
        echo "</div>\n\n";
        Ht::stash_script("jQuery('#opt{$this->id}_div').click(function(e){if(e.target==this)jQuery(this).find('input').click();})");
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->value ? true : false;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        return $qreq["opt$this->id"] > 0;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_bool($pj) || $pj === null)
            return $pj ? 1 : null;
        $ps->error_at_option($this, "Option should be “true” or “false”.");
    }

    function list_display($isrow) {
        return $isrow ? true : ["column" => true, "className" => "pl_option plc"];
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $v = $row->option($this->id);
        return $v && $v->value ? "✓" : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $v = $row->option($this->id);
        return $v && $v->value ? "Y" : "N";
    }

    function unparse_page_html(PaperInfo $row, PaperOptionValue $ov) {
        if ($ov->value)
            return [self::PAGE_HTML_NAME, "✓&nbsp;" . htmlspecialchars($this->name)];
        else
            return false;
    }
}

class SelectorPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [200, "selector", "Selector"];
        $options[] = [300, "radio", "Radio buttons"];
    }

    function has_selector() {
        return true;
    }

    function example_searches() {
        $x = parent::example_searches();
        if (count($this->selector) > 1) {
            if (preg_match('/\A\w+\z/', $this->selector[1]))
                $x["selector"] = array("{$this->search_keyword()}:" . strtolower($this->selector[1]), $this);
            else if (!strpos($this->selector[1], "\""))
                $x["selector"] = array("{$this->search_keyword()}:\"{$this->selector[1]}\"", $this);
        }
        return $x;
    }

    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = $reqv === null ? $ov->value : $reqv;
        $reqv = isset($this->selector[$reqv]) ? $reqv : 0;
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">';
        if ($this->type === "selector")
            echo Ht::select("opt$this->id", $this->selector, $reqv,
                ["data-default-value" => $ov->value]);
        else
            foreach ($this->selector as $val => $text) {
                echo Ht::radio("opt$this->id", $val, $val == $reqv,
                    ["data-default-checked" => $val == $ov->value]),
                    "&nbsp;", Ht::label(htmlspecialchars($text)), "<br />\n";
            }
        echo "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return get($this->selector, $ov->value, null);
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        $v = trim((string) $qreq["opt$this->id"]);
        if ($v === "")
            return null;
        else if (ctype_digit($v)) {
            $iv = intval($v);
            if (isset($this->selector[$iv]))
                return $this->selector[$iv];
        }
        return $v;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_string($pj)
            && ($v = array_search($pj, $this->selector)) !== false)
            $pj = $v;
        if ((is_int($pj) && isset($this->selector[$pj])) || $pj === null)
            return $pj;
        $ps->error_at_option($this, "Option doesn’t match any of the selectors.");
    }

    function list_display($isrow) {
        return true;
    }
    private function unparse_value(PaperOptionValue $ov = null) {
        return $ov ? get($this->selector, $ov->value, "") : "";
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        return htmlspecialchars($this->unparse_value($row->option($this->id)));
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        return $this->unparse_value($row->option($this->id));
    }

    function unparse_page_html_data(PaperInfo $row, PaperOptionValue $ov) {
        return htmlspecialchars($this->unparse_value($ov));
    }
}

class DocumentPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [600, "pdf", "PDF"];
        $options[] = [610, "slides", "Slides"];
        $options[] = [620, "video", "Video"];
        $options[] = [699, "document", "File upload"];
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

    function value_compare($av, $bv) {
        return ($av && $av->value ? 1 : 0) - ($bv && $bv->value ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $pt->echo_editable_document($this, $ov->value ? : 0, 0);
        echo "</div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        if (!$ov->value)
            return null;
        else if (($doc = $ps->document_to_json($this->id, $ov->value)))
            return $doc;
        else
            return false;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        if ($qreq->has_file("opt$this->id"))
            return DocumentInfo::make_file_upload($pj->pid, $this->id, $qreq->file("opt$this->id"));
        else if ($qreq["remove_opt$this->id"])
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
        $e = "I only accept " . htmlspecialchars(Mimetype::description($mimetypes)) . " files.";
        $e .= " (Your file has MIME type “" . htmlspecialchars($doc->mimetype) . "” and starts with “" . htmlspecialchars(substr($doc->content, 0, 5)) . "”.)<br />Please convert your file to a supported type and try again.";
        set_error_html($doc, $e);
        return false;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_object($pj)) {
            $ps->upload_document($pj, $this);
            return $pj->docid;
        } else if ($pj !== null)
            $ps->error_at_option($this, "Option should be a document.");
    }

    function list_display($isrow) {
        return true;
    }
    private function first_document(PaperOptionValue $ov = null) {
        $d = null;
        foreach ($ov ? $ov->documents() : [] as $d)
            break;
        return $d;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $d = $this->first_document($row->option($this->id));
        return $d ? $d->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE) : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $d = $this->first_document($row->option($this->id));
        return $d ? $d->filename : "";
    }
    function unparse_page_html(PaperInfo $row, PaperOptionValue $ov) {
        if (($d = $this->first_document($row->option($this->id)))) {
            $diflags = DocumentInfo::L_SMALL;
            if ($this->display() === self::DISP_SUBMISSION)
                $diflags = 0;
            return [self::PAGE_HTML_FULL, $d->link_html('<span class="pavfn">' . htmlspecialchars($this->name) . '</span>', $diflags)];
        } else
            return false;
    }

    function format_spec() {
        $speckey = "sub_banal";
        if ($this->id)
            $speckey .= ($this->id < 0 ? "_m" : "_") . abs($this->id);
        $fspec = new FormatSpec;
        if (($spects = $this->conf->setting($speckey)))
            $fspec->merge($this->conf->setting_data($speckey, ""), $spects);
        if (($xspec = $this->conf->opt($speckey)))
            $fspec->merge($xspec, $this->conf->opt_timestamp());
        return $fspec;
    }
}

class NumericPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [400, "numeric", "Numeric"];
    }

    function example_searches() {
        $x = parent::example_searches();
        $x["numeric"] = array("{$this->search_keyword()}:>100", $this);
        return $x;
    }

    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = (string) ($reqv === null ? $ov->value : $reqv);
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::entry("opt$this->id", $reqv, ["size" => 8, "class" => trim($pt->error_class("opt$this->id")), "data-default-value" => $ov->value]),
            "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->value;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        $v = trim((string) $qreq["opt$this->id"]);
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
    private function unparse_value(PaperOptionValue $ov = null) {
        return $ov && $ov->value !== null ? $ov->value : "";
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        return $this->unparse_value($row->option($this->id));
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        return $this->unparse_value($row->option($this->id));
    }
    function unparse_page_html_data(PaperInfo $row, PaperOptionValue $ov) {
        return $this->unparse_value($ov);
    }
}

class TextPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [500, "text", "Text"];
        $mtype = "text:ds_5";
        if ($o && $o->display_space > 3)
            $mtype = "text:ds_" . $o->display_space;
        $options[] = [550, $mtype, "Multiline text"];
    }

    function value_compare($av, $bv) {
        $av = $av ? $av->data() : null;
        $av = $av !== null ? $av : "";
        $bv = $bv ? $bv->data() : null;
        $bv = $bv !== null ? $bv : "";
        if ($av !== "" && $bv !== "")
            return strcasecmp($av, $bv);
        else
            return ($bv !== "" ? 1 : 0) - ($av !== "" ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = (string) ($reqv === null ? $ov->data() : $reqv);
        $pt->echo_editable_option_papt($this);
        $fi = $pt->prow ? $pt->prow->edit_format() : $pt->conf->format_info(null);
        echo '<div class="papev">',
            ($fi ? $fi->description_preview_html() : ""),
            Ht::textarea("opt$this->id", $reqv, ["class" => "papertext" . $pt->error_class("opt$this->id"), "rows" => max($this->display_space, 1), "cols" => 60, "spellcheck" => "true", "data-default-value" => $ov->data()]),
            "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        $x = $ov->data();
        return $x !== "" ? $x : null;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        $x = trim((string) $qreq["opt$this->id"]);
        return $x !== "" ? $x : null;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_string($pj))
            return $pj === "" ? null : [1, $pj];
        else if ($pj !== null)
            $ps->error_at_option($this, "Option should be a string.");
    }

    private function unparse_html(PaperInfo $row, PaperOptionValue $ov, PaperList $pl = null) {
        $d = $ov->data();
        if ($d === null || $d === "")
            return "";
        $klass = "";
        if ($pl)
            $klass = strlen($d) > 190 ? "pl_longtext " : "pl_shorttext ";
        if (($format = $row->format_of($d))) {
            if ($pl)
                $pl->need_render = true;
            Ht::stash_script('$(render_text.on_page)', 'render_on_page');
            return '<div class="' . $klass . 'need-format" data-format="'
                . $format . ($pl ? '.plx' : '.abs') . '">'
                . htmlspecialchars($d) . '</div>';
        } else if ($pl)
            return '<div class="' . rtrim($klass) . '">' . Ht::format0($d) . '</div>';
        else
            return Ht::format0($d);
    }

    function list_display($isrow) {
        return ["row" => true, "className" => "pl_textoption"];
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, $pl) : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->id);
        return (string) ($ov ? $ov->data() : "");
    }
    function unparse_page_html_data(PaperInfo $row, PaperOptionValue $ov) {
        return $this->unparse_html($row, $ov, null);
    }
}

class AttachmentsPaperOption extends PaperOption {
    function __construct($args, $conf) {
        parent::__construct($args, $conf);
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
        $options[] = [700, "attachments", "Attachments"];
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
        $pt->echo_editable_option_papt($this, htmlspecialchars($this->name) . ' <span class="papfnh">(max ' . ini_get("upload_max_filesize") . "B per file)</span>");
        echo '<div class="papev">';
        $docclass = new HotCRPDocument($this->conf, $this->id, $this);
        foreach ($ov->documents() as $doc) {
            $oname = "opt" . $this->id . "_" . $doc->paperStorageId;
            echo "<div id=\"removable_$oname\" class=\"ug foldo\"><table id=\"current_$oname\"><tr>",
                "<td class=\"nw\">", $doc->link_html(htmlspecialchars($doc->unique_filename)), "</td>",
                '<td class="fx"><span class="sep"></span></td>',
                "<td class=\"fx\"><a id=\"remover_$oname\" href=\"#remover_$oname\" onclick=\"return doremovedocument(this)\">Delete</a></td>";
            if (($stamps = PaperTable::pdf_stamps_html($doc)))
                echo '<td class="fx"><span class="sep"></span></td><td class="fx">', $stamps, "</td>";
            echo "</tr></table></div>\n";
        }
        echo '<div id="opt', $this->id, '_new"></div>',
            Ht::js_button("Add attachment", "addattachment($this->id)"),
            "</div></div>\n\n";
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        $attachments = array();
        foreach ($ov->documents() as $doc)
            if (($doc = $ps->document_to_json($this->id, $doc)))
                $attachments[] = $doc;
        return empty($attachments) ? null : $attachments;
    }

    function parse_request($opt_pj, Qrequest $qreq, Contact $user, $pj) {
        $attachments = $opt_pj ? : [];
        $opfx = "opt{$this->id}_";
        foreach ($qreq->files() as $k => $v)
            if (str_starts_with($k, $opfx))
                $attachments[] = DocumentInfo::make_file_upload($pj->pid, $this->id, $v);
        for ($i = 0; $i < count($attachments); ++$i)
            if (isset($attachments[$i]->docid)
                && $qreq["remove_opt{$this->id}_{$attachments[$i]->docid}"]) {
                array_splice($attachments, $i, 1);
                --$i;
            }
        return empty($attachments) ? null : $attachments;
    }

    function store_json($pj, PaperStatus $ps) {
        if (is_object($pj))
            $pj = [$pj];
        $result = [];
        foreach ($pj as $docj)
            if (is_object($docj)) {
                $ps->upload_document($docj, $this);
                $result[] = (int) $docj->docid;
            } else
                $ps->error_at_option($this, "Option should be a document.");
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
    private function unparse_links(PaperOptionValue $ov = null, $diflags) {
        $links = [];
        foreach ($ov ? $ov->documents() : [] as $d) {
            $linkname = htmlspecialchars($d->unique_filename);
            if ($diflags === 0)
                $linkname = '<span class="pavfn">' . htmlspecialchars($this->name) . '</span>/' . $linkname;
            $link = $d->link_html($linkname, $diflags);
            if ($d->docclass->is_archive($d)) {
                $link = '<span class="archive foldc"><a href="#" class="expandarchive qq">' . expander(null, 0) . "</a>&nbsp;" . $link . "</span>";
                Ht::stash_script('$(function(){$(document.body).on("click", ".expandarchive", expand_archive)})', "expand_archive");
            }
            $links[] = $link;
        }
        return $links;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $diflags = DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE;
        $links = $this->unparse_links($row->option($this->id), $diflags);
        if ($isrow)
            return join(';&nbsp; ', $links);
        else
            return join('', array_map(function ($x) { return "<div>$x</div>"; }, $links));
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->id);
        $docs = $ov ? $ov->documents() : [];
        return join('; ', array_map(function ($d) { return $d->unique_filename; }, $docs));
    }
    function unparse_page_html(PaperInfo $row, PaperOptionValue $ov) {
        if ($this->display() === self::DISP_SUBMISSION) {
            $links = $this->unparse_links($row->option($this->id), 0);
            array_unshift($links, self::PAGE_HTML_FULL);
        } else {
            $links = $this->unparse_links($row->option($this->id), DocumentInfo::L_SMALL);
            array_unshift($links, self::PAGE_HTML_DATA);
        }
        return $links;
    }
}

class UnknownPaperOption extends PaperOption {
    function __construct($id, $conf) {
        parent::__construct(["id" => $id, "name" => "__unknown{$id}__", "type" => "__unknown{$id}__"], $conf);
    }

    function takes_multiple() {
        return true;
    }
}
