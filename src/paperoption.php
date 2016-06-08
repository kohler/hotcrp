<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperOptionValue {
    public $prow;
    public $id;
    public $option;
    public $value;
    public $values;
    public $data;
    public $data_array;
    public $anno = null;
    private $_documents = null;

    public function __construct($prow, PaperOption $o, $values = [], $data_array = []) {
        $this->prow = $prow;
        $this->id = $o->id;
        $this->option = $o;
        $this->values = $values;
        $this->data_array = $data_array;
        if ($o->takes_multiple() && $o->has_attachments())
            array_multisort($this->data_array, SORT_NUMERIC, $this->values);
        if (count($values) == 1 || !$o->takes_multiple()) {
            $this->value = get($this->values, 0);
            if (!empty($this->data_array))
                $this->data = $this->data_array[0];
        }
    }
    public function documents() {
        assert($this->prow || empty($this->values));
        assert($this->option->has_document_storage());
        if ($this->_documents === null) {
            $this->_documents = $by_unique_filename = array();
            $docclass = null;
            foreach ($this->values as $docid)
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
    public function document($index) {
        return get($this->documents(), $index);
    }
    public function document_content($index) {
        if (($doc = $this->document($index))
            && $doc->docclass->load($doc)
            && ($content = Filer::content($doc)))
            return $content;
        return false;
    }
}

class PaperOption {
    const MINFIXEDID = 1000000;

    public $id;
    public $name;
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

    static private $jlist = null;
    static private $jmap = [];
    static private $list = null;
    static private $nonfixed_list = null;
    static private $docmap = [];

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

    function __construct($args) {
        if (is_object($args))
            $args = get_object_vars($args);
        $this->id = (int) $args["id"];
        $this->name = $args["name"];
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

        if (($x = get($args, "display_space")))
            $this->display_space = (int) $x;
        $this->selector = get($args, "selector");
    }

    static function make($args) {
        global $Opt;
        if (is_object($args))
            $args = get_object_vars($args);
        if (($factory = get($args, "factory")))
            return call_user_func($factory, $args);
        $fclass = get($args, "factory_class");
        $fclass = $fclass ? : get(self::$factory_class_map, get($args, "type"));
        $fclass = $fclass ? : "PaperOption";
        return new $fclass($args);
    }

    static function compare($a, $b) {
        $ap = isset($a->position) ? (float) $a->position : 99999;
        $bp = isset($b->position) ? (float) $b->position : 99999;
        if ($ap != $bp)
            return $ap < $bp ? -1 : 1;
        else
            return $a->id - $b->id;
    }

    static public function _add_json($oj, $fixed) {
        if (is_string($oj->id) && is_numeric($oj->id))
            $oj->id = intval($oj->id);
        if (is_int($oj->id) && !isset(self::$jlist[$oj->id])
            && ($oj->id >= self::MINFIXEDID) === $fixed
            && isset($oj->name) && is_string($oj->name)) {
            if (!isset($oj->abbr) || $oj->abbr == "")
                $oj->abbr = self::abbreviate($oj->name, $oj->id);
            self::$jlist[$oj->id] = $oj;
            return true;
        } else
            return false;
    }

    static function option_json_list(Conf $c = null) {
        global $Conf, $Opt;
        if (self::$jlist === null) {
            self::$jlist = self::$jmap = [];
            $c = $c ? : $Conf;
            if (($olist = $c->setting_json("options")))
                expand_json_includes_callback($olist, "PaperOption::_add_json", false);
            if (($olist = opt("fixedOptions")))
                expand_json_includes_callback($olist, "PaperOption::_add_json", true);
            uasort(self::$jlist, ["PaperOption", "compare"]);
        }
        return self::$jlist;
    }

    static function option_ids(Conf $c = null) {
        $m = [];
        foreach (self::option_json_list($c) as $id => $oj)
            if (!get($oj, "nonpaper"))
                $m[] = $id;
        return $m;
    }

    static function find($id, $force = false) {
        if (array_key_exists($id, self::$jmap))
            $o = self::$jmap[$id];
        else {
            $oj = get(self::option_json_list(), $id);
            $o = self::$jmap[$id] = $oj ? PaperOption::make($oj) : null;
        }
        if (!$o && $force)
            $o = self::$jmap[$id] = new UnknownPaperOption($id);
        return $o;
    }

    static function option_list(Conf $c = null) {
        global $Conf, $Opt;
        if (self::$list === null) {
            self::$list = [];
            foreach (self::option_json_list($c) as $id => $oj)
                if (!get($oj, "nonpaper")
                    && ($o = self::find($id)) && !$o->nonpaper)
                    self::$list[$id] = $o;
        }
        return self::$list;
    }

    static function nonfixed_option_list(Conf $c = null) {
        if (self::$nonfixed_list === null) {
            self::$nonfixed_list = [];
            foreach (self::option_json_list($c) as $id => $oj)
                if ($id < self::MINFIXEDID && !get($oj, "nonpaper")
                    && ($o = self::find($id)) && !$o->nonpaper)
                    self::$nonfixed_list[$id] = $o;
        }
        return self::$nonfixed_list;
    }

    static function nonfinal_option_list() {
        $list = [];
        foreach (self::option_json_list() as $id => $oj)
            if (!get($oj, "nonpaper") && !get($oj, "final")
                && ($o = self::find($id)) && !$o->nonpaper && !$o->final)
                $list[$id] = $o;
        return $list;
    }

    static function user_option_list(Contact $user) {
        global $Conf;
        if ($Conf->has_any_accepts() && $user->can_view_some_decision())
            return PaperOption::option_list();
        else
            return PaperOption::nonfinal_option_list();
    }

    static function invalidate_option_list() {
        self::$jlist = self::$list = self::$nonfixed_list = null;
        self::$jmap = self::$docmap = [];
    }

    static function count_option_list() {
        return count(self::option_json_list());
    }

    static function find_document($id) {
        if (!array_key_exists($id, self::$docmap)) {
            if ($id == DTYPE_SUBMISSION)
                $o = new DocumentPaperOption(array("id" => DTYPE_SUBMISSION, "name" => "Submission", "abbr" => "paper", "type" => null, "position" => 0));
            else if ($id == DTYPE_FINAL)
                $o = new DocumentPaperOption(array("id" => DTYPE_FINAL, "name" => "Final version", "abbr" => "final", "type" => null, "final" => true, "position" => 0));
            else
                $o = self::find($id);
            self::$docmap[$id] = $o;
        }
        return self::$docmap[$id];
    }

    static function search($name) {
        $name = strtolower($name);
        if ($name === (string) DTYPE_SUBMISSION
            || $name === "paper"
            || $name === "submission")
            return array(DTYPE_SUBMISSION => self::find_document(DTYPE_SUBMISSION));
        else if ($name === (string) DTYPE_FINAL
                 || $name === "final")
            return array(DTYPE_FINAL => self::find_document(DTYPE_FINAL));
        if ($name === "" || $name === "none")
            return array();
        if ($name === "any")
            return self::option_list();
        if (substr($name, 0, 4) === "opt-")
            $name = substr($name, 4);
        $oabbr = array();
        foreach (self::option_json_list() as $id => $oj)
            if ($oj->abbr === $name) {
                $oabbr = [$id => $oj->abbr];
                break;
            } else
                $oabbr[$id] = $oj->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        $omap = [];
        foreach ($oabbr as $id => $x)
            if (($o = self::find($id)) && !$o->nonpaper)
                $omap[$id] = $o;
        return $omap;
    }

    static function match($name) {
        $omap = self::search($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
    }

    static function search_nonpaper($name) {
        $name = strtolower($name);
        $oabbr = array();
        foreach (self::option_json_list() as $id => $oj)
            if ($oj->abbr === $name) {
                $oabbr = [$id => $oj->abbr];
                break;
            } else
                $oabbr[$id] = $oj->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        $omap = [];
        foreach ($oabbr as $id => $x)
            if (($o = self::find($id)) && $o->nonpaper)
                $omap[$id] = $o;
        return $omap;
    }

    static function match_nonpaper($name) {
        $omap = self::search_nonpaper($name);
        reset($omap);
        return count($omap) == 1 ? current($omap) : null;
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

    function has_document_storage() {
        return $this->has_document();
    }

    function has_attachments() {
        return false;
    }

    function mimetypes() {
        return null;
    }

    function needs_data() {
        return false;
    }

    function takes_multiple() {
        return false;
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
        return ["has" => ["has:$this->abbr", $this],
                "yes" => ["{$this->abbr}:yes", $this]];
    }

    function add_search_completion(&$res) {
        array_push($res, "has:{$this->abbr}", "opt:{$this->abbr}");
    }

    private static function load_optdata($pid) {
        $result = Dbl::qe("select optionId, value, data from PaperOption where paperId=?", $pid);
        $optdata = array();
        while (($row = edb_row($result)))
            $optdata[$row[0] . "." . $row[1]] = $row[2];
        Dbl::free($result);
        return $optdata;
    }

    static function parse_paper_options(PaperInfo $prow, $all) {
        $optionIds = get($prow, "optionIds");
        if ($optionIds === "")
            return [];

        $optsel = array();
        if ($optionIds !== null) {
            preg_match_all('/(\d+)#(\d+)/', $optionIds, $m);
            for ($i = 0; $i < count($m[1]); ++$i)
                arrayappend($optsel[$m[1][$i]], (int) $m[2][$i]);
            $optdata = null;
        } else {
            $optdata = self::load_optdata($prow->paperId);
            foreach ($optdata as $k => $v) {
                $dot = strpos($k, ".");
                arrayappend($optsel[substr($k, 0, $dot)], (int) substr($k, $dot + 1));
            }
        }

        $option_array = array();
        foreach ($optsel as $oid => $ovalues) {
            $o = PaperOption::find($oid, $all);
            if (!$o)
                continue;
            $needs_data = $o->needs_data();
            if ($needs_data && !$optdata)
                $optdata = self::load_optdata($prow->paperId);
            $odata = [];
            if ($needs_data)
                foreach ($ovalues as $v)
                    $odata[] = $optdata[$oid . "." . $v];
            $option_array[$oid] = new PaperOptionValue($prow, $o, $ovalues, $odata);
        }
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

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return null;
    }

    function parse_json($pj, PaperStatus $ps) {
        return null;
    }

    function unparse_column_html(PaperList $pl, $row) {
        return "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return "";
    }
}

class CheckboxPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
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
        $ps->set_option_error_html($this, "Option should be “true” or “false”.");
    }

    function unparse_column_html(PaperList $pl, $row) {
        $v = $row->option($this->id);
        return $v && $v->value ? "✓" : "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $ov->value ? ["✓&nbsp;" . htmlspecialchars($this->name), true] : "";
    }
}

class SelectorPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
    }

    function has_selector() {
        return true;
    }

    function example_searches() {
        $x = parent::example_searches();
        if (count($this->selector) > 1) {
            if (preg_match('/\A\w+\z/', $this->selector[1]))
                $x["selector"] = array("{$this->abbr}:" . strtolower($this->selector[1]), $this);
            else if (!strpos($this->selector[1], "\""))
                $x["selector"] = array("{$this->abbr}:\"{$this->selector[1]}\"", $this);
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
        $ps->set_option_error_html($this, "Option doesn’t match any of the selectors.");
    }

    function unparse_column_html(PaperList $pl, $row) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_page_html($row, $ov) : "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        if (isset($this->selector[$ov->value]))
            return htmlspecialchars($this->selector[$ov->value]);
        return "";
    }
}

class DocumentPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
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
        if ($qreq->_FILES["opt$this->id"])
            return DocumentInfo::make_file_upload($pj->pid, $this->id, $qreq->_FILES["opt$this->id"]);
        else if ($qreq["remove_opt$this->id"])
            return null;
        else
            return $opt_pj;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        if ($ov->value && ($doc = $ps->document_to_json($this->id, $ov->value)))
            return $doc;
        return null;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_object($pj)) {
            $ps->upload_document($pj, $this);
            return $pj->docid;
        }
        $ps->set_option_error_html($this, "Option should be a document.");
    }

    function unparse_column_html(PaperList $pl, $row) {
        if (($v = $row->option($this->id)))
            foreach ($v->documents() as $d)
                return $d->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE);
        return "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        foreach ($ov->documents() as $d)
            return [$d->link_html(htmlspecialchars($this->name), DocumentInfo::L_SMALL), true];
        return "";
    }
}

class NumericPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
    }

    function example_searches() {
        $x = parent::example_searches();
        $x["numeric"] = array("{$this->abbr}:>100", $this);
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
        return $v !== "" && ctype_digit($v) ? (int) $v : $v;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->value ? : null;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_int($pj))
            return $pj;
        else if ($pj === "" || $pj === null)
            return null;
        $ps->set_option_error_html($this, "Option should be an integer.");
    }

    function unparse_column_html(PaperList $pl, $row) {
        return $this->unparse_page_html($row);
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        $v = $row->option($this->id);
        return $v && $v->value !== null ? $v->value : "";
    }
}

class TextPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
    }

    function needs_data() {
        return true;
    }

    function value_compare($av, $bv) {
        $av = $av ? $av->data : null;
        $av = $av !== null ? $av : "";
        $bv = $bv ? $bv->data : null;
        $bv = $bv !== null ? $bv : "";
        if ($av !== "" && $bv !== "")
            return strcasecmp($av, $bv);
        else
            return ($bv !== "" ? 1 : 0) - ($av !== "" ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $reqv = (string) ($reqv === null ? $ov->data : $reqv);
        $pt->echo_editable_option_papt($this);
        echo '<div class="papev">',
            Ht::textarea("opt$this->id", $reqv, ["class" => "papertext" . $pt->error_class("opt$this->id"), "rows" => max($this->display_space, 1), "cols" => 60, "onchange" => "hiliter(this)", "spellcheck" => "true"]),
            "</div></div>\n\n";
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        return trim((string) $qreq["opt$this->id"]);
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        return $ov->data != "" ? $ov->data : null;
    }

    function parse_json($pj, PaperStatus $ps) {
        if (is_string($pj))
            return trim($pj) === "" ? null : [1, $pj];
        $ps->set_option_error_html($this, "Option should be a string.");
    }

    private function unparse_html($row, PaperOptionValue $ov, PaperList $pl = null) {
        if ($ov->data === null || $ov->data === "")
            return "";
        if (($format = $row->format_of($ov->data))) {
            if ($pl)
                $pl->need_render = true;
            return '<div class="need-format" data-format="' . $format
                . ($pl ? '.plx' : '.abs') . '">' . htmlspecialchars($ov->data) . '</div>';
        } else
            return '<div class="format0">' . Ht::link_urls(htmlspecialchars($ov->data)) . '</div>';
    }

    function unparse_column_html(PaperList $pl, $row) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, $pl) : "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $this->unparse_html($row, $ov, null);
    }
}

class AttachmentsPaperOption extends PaperOption {
    function __construct($args) {
        parent::__construct($args);
    }

    function has_document() {
        return true;
    }

    function has_attachments() {
        return true;
    }

    function needs_data() {
        return true;
    }

    function takes_multiple() {
        return true;
    }

    function example_searches() {
        $x = parent::example_searches();
        $x["attachment-count"] = array("{$this->abbr}:>2", $this);
        $x["attachment-filename"] = array("{$this->abbr}:*.gif", $this);
        return $x;
    }

    function value_compare($av, $bv) {
        return ($av && count($av->values) ? 1 : 0) - ($bv && count($bv->values) ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $pt->echo_editable_option_papt($this, htmlspecialchars($this->name) . ' <span class="papfnh">(max ' . ini_get("upload_max_filesize") . "B per file)</span>");
        echo '<div class="papev">';
        $docclass = new HotCRPDocument($this->id, $this);
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
        foreach ($qreq->_FILES ? : [] as $k => $v)
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
        return empty($attachments) ? null : $attachments;
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
                $ps->set_option_error_html($this, "Option should be a document.");
        return $result;
    }

    private function unparse_html($row, PaperOptionValue $ov, $flags) {
        $docs = [];
        foreach ($ov->documents() as $d)
            $docs[] = $d->link_html(htmlspecialchars($d->unique_filename), $flags);
        return join("<br />", $docs);
    }

    function unparse_column_html(PaperList $pl, $row) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE) : "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $this->unparse_html($row, $ov, DocumentInfo::L_SMALL);
    }
}

class UnknownPaperOption extends PaperOption {
    function __construct($id) {
        parent::__construct(["id" => $id, "name" => "__unknown{$id}__", "type" => "__unknown{$id}__"]);
    }

    function needs_data() {
        return true;
    }

    function takes_multiple() {
        return true;
    }
}
