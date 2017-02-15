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
            $this->option->sort_values($this->_values, $this->_data_array);
        if (count($this->_values) == 1 || !$this->option->takes_multiple()) {
            $this->value = get($this->_values, 0);
            if (!empty($this->_data_array))
                $this->_data = $this->_data_array[0];
        }
        if ($this->_documents && $this->_values != $old_values)
            $this->_documents = null;
        $this->anno = null;
    }
    function documents() {
        assert($this->prow || empty($this->_values));
        assert($this->option->has_document_storage());
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
            && $doc->docclass->load($doc)
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
    function value_count() {
        return count($this->_values);
    }
    function unsorted_values() {
        return $this->_values;
    }
    function sorted_values() {
        if ($this->_data_array === null && count($this->_values) > 1)
            $this->_load_data();
        return $this->_values;
    }
    function data() {
        if ($this->_data_array === null)
            $this->_load_data();
        return $this->_data;
    }
    private function _load_data() {
        if ($this->_data_array === null) {
            $odata = PaperOption::load_optdata($this->prow);
            if ($this->option instanceof UnknownPaperOption)
                $allopt = $this->prow->all_options();
            else
                $allopt = $this->prow->options();
            foreach ($allopt as $oid => $option)
                $option->assign($odata["v"][$oid], $odata["d"][$oid]);
            if ($this->_data_array === null)
                $this->assign($odata["v"][$this->id], $odata["d"][$this->id]);
        }
    }
    function invalidate() {
        $result = $this->prow->conf->qe("select value, `data` from PaperOption where paperId=? and optionId=?", $this->prow->paperId, $this->id);
        $values = $data_array = [];
        while ($result && ($row = $result->fetch_row())) {
            $values[] = (int) $row[0];
            $data_array[] = $row[1];
        }
        Dbl::free($result);
        $this->assign($values, $data_array);
    }
}

class PaperOptionList {
    private $conf;
    private $jlist = null;
    private $jmap = [];
    private $list = null;
    private $nonfixed_list = null;
    private $docmap;

    function __construct($conf) {
        global $Conf;
        $this->conf = $conf ? : $Conf;
        $this->init_docmap();
    }

    private function init_docmap() {
        $this->docmap = [
            DTYPE_SUBMISSION => new DocumentPaperOption(["id" => DTYPE_SUBMISSION, "name" => "Submission", "message_name" => "submission", "abbr" => "paper", "type" => null, "position" => 0], $this->conf),
            DTYPE_FINAL => new DocumentPaperOption(["id" => DTYPE_FINAL, "name" => "Final version", "message_name" => "final version", "abbr" => "final", "type" => null, "final" => true, "position" => 0], $this->conf)
        ];
    }

    private function check_require_setting($require_setting) {
        return str_starts_with($require_setting, "opt.")
            ? !!$this->conf->opt(substr($require_setting, 4))
            : !!$this->conf->setting($require_setting);
    }

    function _add_json($oj, $fixed) {
        if (is_string($oj->id) && is_numeric($oj->id))
            $oj->id = intval($oj->id);
        if (is_int($oj->id) && !isset($this->jlist[$oj->id])
            && ($oj->id >= PaperOption::MINFIXEDID) === $fixed
            && isset($oj->name) && is_string($oj->name)) {
            // ignore option if require_setting not satisfied
            if (isset($oj->require_setting) && is_string($oj->require_setting)
                && !$this->check_require_setting($oj->require_setting))
                return true;
            if (!isset($oj->abbr) || $oj->abbr == "")
                $oj->abbr = PaperOption::abbreviate($oj->name, $oj->id);
            $this->jlist[$oj->id] = $oj;
            return true;
        } else
            return false;
    }

    function option_json_list() {
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

    function find($id, $force = false) {
        if (array_key_exists($id, $this->jmap))
            $o = $this->jmap[$id];
        else {
            $o = null;
            if (($oj = get($this->option_json_list(), $id)))
                $o = PaperOption::make($oj, $this->conf);
            if ($o && $o->require_setting && !$this->check_require_setting($o->require_setting))
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
                    && ($o = $this->find($id)) && !$o->nonpaper)
                    $this->list[$id] = $o;
        }
        return $this->list;
    }

    function nonfixed_option_list() {
        if ($this->nonfixed_list === null) {
            $this->nonfixed_list = [];
            foreach ($this->option_json_list() as $id => $oj)
                if ($id < PaperOption::MINFIXEDID && !get($oj, "nonpaper")
                    && ($o = $this->find($id)) && !$o->nonpaper)
                    $this->nonfixed_list[$id] = $o;
        }
        return $this->nonfixed_list;
    }

    function nonfinal_option_list() {
        $list = [];
        foreach ($this->option_json_list() as $id => $oj)
            if (!get($oj, "nonpaper") && !get($oj, "final")
                && ($o = $this->find($id)) && !$o->nonpaper && !$o->final)
                $list[$id] = $o;
        return $list;
    }

    function invalidate_option_list() {
        $this->jlist = $this->list = $this->nonfixed_list = null;
        $this->jmap = [];
        $this->init_docmap();
    }

    function count_option_list() {
        return count($this->option_json_list());
    }

    function find_document($id) {
        if (!array_key_exists($id, $this->docmap))
            $this->docmap[$id] = $this->find($id);
        return $this->docmap[$id];
    }

    function search($name) {
        $name = strtolower($name);
        if ($name === (string) DTYPE_SUBMISSION
            || $name === "paper"
            || $name === "submission")
            return array(DTYPE_SUBMISSION => $this->find_document(DTYPE_SUBMISSION));
        else if ($name === (string) DTYPE_FINAL
                 || $name === "final")
            return array(DTYPE_FINAL => $this->find_document(DTYPE_FINAL));
        if ($name === "" || $name === "none")
            return array();
        if ($name === "any")
            return $this->option_list();
        if (substr($name, 0, 4) === "opt-")
            $name = substr($name, 4);
        $oabbr = array();
        foreach ($this->option_json_list() as $id => $oj)
            if ($oj->abbr === $name) {
                $oabbr = [$id => $oj->abbr];
                break;
            } else
                $oabbr[$id] = $oj->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        $omap = [];
        foreach ($oabbr as $id => $x)
            if (($o = $this->find($id)) && !$o->nonpaper)
                $omap[$id] = $o;
        return $omap;
    }

    function match($name) {
        $omap = $this->search($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }

    function search_nonpaper($name) {
        $name = strtolower($name);
        $oabbr = array();
        foreach ($this->option_json_list() as $id => $oj)
            if ($oj->abbr === $name) {
                $oabbr = [$id => $oj->abbr];
                break;
            } else
                $oabbr[$id] = $oj->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        $omap = [];
        foreach ($oabbr as $id => $x)
            if (($o = $this->find($id)) && $o->nonpaper)
                $omap[$id] = $o;
        return $omap;
    }

    function match_nonpaper($name) {
        $omap = $this->search_nonpaper($name);
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

class PaperOption {
    const MINFIXEDID = 1000000;

    public $id;
    public $conf;
    public $name;
    public $message_name;
    public $type; // checkbox, selector, radio, numeric, text,
                  // pdf, slides, video, attachments, ...
    public $abbr;
    public $description;
    public $position;
    public $final;
    public $nonpaper;
    public $visibility; // "rev", "nonblind", "admin"
    private $display;
    public $display_space;
    public $selector;
    private $form_priority;
    public $require_setting; // public for PaperOptionList

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
        $this->abbr = get_s($args, "abbr");
        if ($this->abbr == "")
            $this->abbr = self::abbreviate($this->name, $this->id);
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
        $this->display = get(self::$display_map, $disp, self::DISP_DEFAULT);
        $this->form_priority = get_i($args, "form_priority");
        $this->require_setting = get($args, "require_setting");

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

    static function abbreviate($name, $id) {
        $abbr = strtolower(UnicodeHelper::deaccent($name));
        $abbr = preg_replace('/[^a-z_0-9]+/', "-", $abbr);
        $abbr = preg_replace('/^-+|-+$/', "", $abbr);
        if (preg_match('/\A(?:|p(?:aper)?\d*|submission|final|opt\d*|\d.*)\z/', $abbr))
            $abbr = "opt$id";
        return $abbr;
    }

    static function type_has_selector($type) {
        return $type === "radio" || $type === "selector";
    }

    function fixed() {
        return $this->id >= self::MINFIXEDID;
    }

    function abbreviation() {
        return $this->abbr;
    }

    function field_key() {
        return $this->id <= 0 ? $this->abbr : "opt" . $this->id;
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

    function search_keyword() {
        return $this->abbr;
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

    function has_document_storage() {
        return $this->has_document();
    }

    function has_attachments() {
        return false;
    }

    function takes_multiple() {
        return false;
    }

    static function list_subform_options(&$options, PaperOption $o = null) {
    }

    static function factory_classes() {
        return array_values(array_unique(self::$factory_class_map));
    }

    function refresh_documents(PaperOptionValue $ov) {
    }

    function sort_values(&$values, &$data_array) {
    }

    function display_name() {
        if (!self::$display_rmap)
            self::$display_rmap = array_flip(self::$display_map);
        return self::$display_rmap[$this->display];
    }

    function unparse() {
        $j = (object) array("id" => (int) $this->id,
                            "name" => $this->name,
                            "abbr" => $this->abbr,
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

    static function load_optdata(PaperInfo $prow) {
        $result = $prow->conf->qe("select optionId, value, data from PaperOption where paperId=?", $prow->paperId);
        $optdata = ["v" => [], "d" => []];
        while (($row = edb_row($result))) {
            $optdata["v"][$row[0]][] = (int) $row[1];
            $optdata["d"][$row[0]][] = $row[2];
        }
        Dbl::free($result);
        return $optdata;
    }

    static function parse_paper_options(PaperInfo $prow, $all) {
        $optionIds = get($prow, "optionIds");
        if ($optionIds === "")
            return [];

        if ($optionIds !== null) {
            preg_match_all('/(\d+)#(-?\d+)/', $optionIds, $m);
            $optdata = ["v" => [], "d" => []];
            for ($i = 0; $i < count($m[1]); ++$i)
                $optdata["v"][$m[1][$i]][] = (int) $m[2][$i];
        } else
            $optdata = self::load_optdata($prow);

        $paper_opts = $prow->conf->paper_opts;
        $option_array = array();
        foreach ($optdata["v"] as $oid => $ovalues)
            if (($o = $paper_opts->find($oid, $all)))
                $option_array[$oid] = new PaperOptionValue($prow, $o, $ovalues, get($optdata["d"], $oid));
        uasort($option_array, function ($a, $b) {
            if ($a->option && $b->option)
                return PaperOption::compare($a->option, $b->option);
            else if ($a->option || $b->option)
                return $a->option ? -1 : 1;
            else
                return $a->id - $b->id;
        });
        return $option_array;
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

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        return null;
    }

    function validate_document(DocumentInfo $doc) {
        return true;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return null;
    }

    function parse_json($pj, PaperStatus $ps) {
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

    function unparse_page_html($row, PaperOptionValue $ov) {
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
        $pt->echo_editable_option_papt($this, Ht::checkbox_h("opt{$this->id}", 1, $reqv) . "&nbsp;" . Ht::label(htmlspecialchars($this->name)));
        echo "</div>\n\n";
        Ht::stash_script("jQuery('#opt{$this->id}_div').click(function(e){if(e.target==this)jQuery(this).find('input').click();})");
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        return $qreq["opt$this->id"] > 0;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->value ? true : false;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_bool($pj))
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

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $ov->value ? ["✓&nbsp;" . htmlspecialchars($this->name), true] : "";
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
            echo Ht::select("opt$this->id", $this->selector, $reqv, ["onchange" => "hiliter(this)"]);
        else
            foreach ($this->selector as $val => $text) {
                echo Ht::radio("opt$this->id", $val, $val == $reqv, ["onchange" => "hiliter(this)"]),
                    "&nbsp;", Ht::label(htmlspecialchars($text)), "<br />\n";
            }
        echo "</div></div>\n\n";
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        $v = trim((string) $qreq["opt$this->id"]);
        return $v !== "" && ctype_digit($v) ? (int) $v : $v;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return get($this->selector, $ov->value, null);
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_string($pj)
            && ($v = array_search($pj, $this->selector)) !== false)
            $pj = $v;
        if (is_int($pj) && isset($this->selector[$pj]))
            return $pj;
        $ps->error_at_option($this, "Option doesn’t match any of the selectors.");
    }

    function list_display($isrow) {
        return true;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_page_html($row, $ov) : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->id);
        if ($ov && isset($this->selector[$ov->value]))
            return $this->selector[$ov->value];
        return "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        if (isset($this->selector[$ov->value]))
            return htmlspecialchars($this->selector[$ov->value]);
        return "";
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

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
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

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        if (!$ov->value)
            return false;
        else if (($doc = $ps->document_to_json($this->id, $ov->value)))
            return $doc;
        else
            return null;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_object($pj)) {
            $ps->upload_document($pj, $this);
            return $pj->docid;
        }
        $ps->error_at_option($this, "Option should be a document.");
    }

    function list_display($isrow) {
        return true;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        if (($ov = $row->option($this->id)))
            foreach ($ov->documents() as $d)
                return $d->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE);
        return "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        if (($ov = $row->option($this->id)))
            foreach ($ov->documents() as $d)
                return $d->filename;
        return "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        foreach ($ov->documents() as $d)
            return [$d->link_html(htmlspecialchars($this->name), DocumentInfo::L_SMALL), true];
        return "";
    }

    function format_spec() {
        $suffix = "";
        if ($this->id)
            $suffix = $this->id < 0 ? "_m" . -$this->id : "_" . $this->id;
        $spec = "";
        if ($this->conf->setting("sub_banal$suffix"))
            $spec = $this->conf->setting_data("sub_banal$suffix", "");
        $fspec = new FormatSpec($spec);
        if (($xspec = $this->conf->opt("sub_banal$suffix")))
            $fspec->merge($xspec);
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
            Ht::entry("opt$this->id", $reqv, ["size" => 8, "onchange" => "hiliter(this)", "class" => trim($pt->error_class("opt$this->id"))]),
            "</div></div>\n\n";
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        $v = trim((string) $qreq["opt$this->id"]);
        $iv = intval($v);
        return $v !== "" && $iv == $v ? $iv : $v;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->value ? : false;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_int($pj))
            return $pj;
        else if ($pj === "" || $pj === null)
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
    function unparse_page_html($row, PaperOptionValue $ov) {
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
        echo '<div class="papev">',
            Ht::textarea("opt$this->id", $reqv, ["class" => "papertext" . $pt->error_class("opt$this->id"), "rows" => max($this->display_space, 1), "cols" => 60, "onchange" => "hiliter(this)", "spellcheck" => "true"]),
            "</div></div>\n\n";
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        return trim((string) $qreq["opt$this->id"]);
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        $d = $ov->data();
        return $d != "" ? $d : false;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_string($pj))
            return trim($pj) === "" ? null : [1, $pj];
        $ps->error_at_option($this, "Option should be a string.");
    }

    private function unparse_html($row, PaperOptionValue $ov, PaperList $pl = null) {
        $d = $ov->data();
        if ($d === null || $d === "")
            return "";
        if (($format = $row->format_of($d))) {
            if ($pl)
                $pl->need_render = true;
            Ht::stash_script('$(render_text.on_page)', 'render_on_page');
            return '<div class="need-format" data-format="' . $format
                . ($pl ? '.plx' : '.abs') . '">' . htmlspecialchars($d) . '</div>';
        } else
            return '<div class="format0">' . Ht::link_urls(htmlspecialchars($d)) . '</div>';
    }

    function list_display($isrow) {
        return ["row" => true];
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, $pl) : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $ov = $row->option($this->id);
        return (string) ($ov ? $ov->data() : "");
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
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

    function sort_values(&$values, &$data_array) {
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

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
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

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        $attachments = array();
        foreach ($ov->documents() as $doc)
            if (($doc = $ps->document_to_json($this->id, $doc)))
                $attachments[] = $doc;
        return empty($attachments) ? false : $attachments;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_object($pj))
            $pj = [$pj];
        $result = [];
        foreach ($pj as $docj)
            if (is_object($docj)) {
                $ps->upload_document($docj, $this);
                $result[] = [$docj->docid, "" . (count($result) + 1)];
            } else
                $ps->error_at_option($this, "Option should be a document.");
        return $result;
    }

    private function unparse_html($row, PaperOptionValue $ov, $flags, $tag) {
        $docs = "";
        foreach ($ov->documents() as $d) {
            $link = $d->link_html(htmlspecialchars($d->unique_filename), $flags);
            if ($d->docclass->is_archive($d)) {
                $link = '<' . $tag . ' class="archive foldc"><a href="#" class="expandarchive qq">' . expander(null, 0) . "</a>&nbsp;" . $link . "</$tag>";
                Ht::stash_script('$(function(){$(".expandarchive").click(expand_archive)})', "expand_archive");
            } else if ($tag == "div")
                $link = "<div>$link</div>";
            if ($docs !== "" && $tag == "span")
                $docs .= ";&nbsp; ";
            $docs .= $link;
        }
        return $docs;
    }

    function list_display($isrow) {
        return true;
    }
    function unparse_list_html(PaperList $pl, PaperInfo $row, $isrow) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE, $isrow ? "span" : "div") : "";
    }
    function unparse_list_text(PaperList $pl, PaperInfo $row) {
        $x = [];
        if (($ov = $row->option($this->id)))
            foreach ($ov->documents() as $d)
                $x[] = $d->unique_filename;
        return join("; ", $x);
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $this->unparse_html($row, $ov, DocumentInfo::L_SMALL, "div");
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
