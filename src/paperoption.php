<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperOptionValue {
    public $id;
    public $option;
    public $value;
    public $values;
    public $data;
    public $data_array;
    private $_documents = null;

    public function __construct($id, PaperOption $o = null, $values = [], $data_array = []) {
        $this->id = $id;
        $this->option = $o;
        $this->values = $values;
        $this->data_array = $data_array;
        if ($o && $o->takes_multiple()) {
            if ($o->type === "attachments")
                array_multisort($this->data_array, SORT_NUMERIC, $this->values);
        } else {
            $this->value = get($this->values, 0);
            if (!empty($this->data_array))
                $this->data = $this->data_array[0];
        }
    }
    public function documents(PaperInfo $prow) {
        assert($this->option->has_document_storage());
        if ($this->_documents === null) {
            $this->_documents = $by_unique_filename = array();
            $docclass = null;
            foreach ($this->values as $docid)
                if ($docid > 1 && ($d = $prow->document($this->id, $docid))) {
                    $d->docclass = $docclass = $docclass ? : new HotCRPDocument($this->id);
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
    public function document(PaperInfo $prow, $index) {
        return get($this->documents($prow), $index);
    }
    public function document_content(PaperInfo $prow, $index) {
        if (($doc = $this->document($prow, $index))
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
    public $visibility; // "rev", "nonblind", "admin"
    private $display;
    public $display_space;
    public $selector;
    private $form_priority;

    static private $jlist = null;
    static private $jmap = [];
    static private $list = null;
    static private $nonfixed_list = null;

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

    static private $type_map = [
        "checkbox" => "CheckboxPaperOption",
        "radio" => "SelectorPaperOption", "selector" => "SelectorPaperOption",
        "numeric" => "NumericPaperOption",
        "text" => "TextPaperOption",
        "pdf" => "DocumentPaperOption", "slides" => "DocumentPaperOption", "video" => "DocumentPaperOption",
        "attachments" => "AttachmentsPaperOption"
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
        if ((is_int($p) || is_float($p)) && $p > 0)
            $this->position = $p;
        else
            $this->position = 99999;
        $this->final = !!get($args, "final");

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
        $type = get($args, "type");
        if (isset($Opt["optionTypeClass.$type"]))
            $typeclass = $Opt["optionTypeClass.$type"];
        else if (isset(self::$type_map[$type]))
            $typeclass = self::$type_map[$type];
        else
            $typeclass = "PaperOption";
        return new $typeclass($args);
    }

    static function compare($a, $b) {
        $ap = isset($a->position) ? (float) $a->position : 99999;
        $bp = isset($b->position) ? (float) $b->position : 99999;
        if ($ap != $bp)
            return $ap < $bp ? -1 : 1;
        else
            return $a->id - $b->id;
    }

    static private function add_json($jlist, $fixed, $landmark) {
        if (is_string($jlist)) {
            if (($jlistx = json_decode($jlist)) !== false)
                $jlist = $jlistx;
            else if (json_last_error()) {
                Json::decode($jlist);
                error_log("$landmark: Invalid JSON. " . Json::last_error_msg());
                return;
            }
            if (is_object($jlist))
                $jlist = [$jlist];
        }
        foreach ($jlist as $oj) {
            if (is_object($oj) && isset($oj->id) && isset($oj->name)) {
                if (is_string($oj->id) && is_numeric($oj->id))
                    $oj->id = intval($oj->id);
                if (is_int($oj->id) && !isset(self::$jlist[$oj->id])
                    && ($oj->id >= self::MINFIXEDID) === $fixed
                    && is_string($oj->name)) {
                    if (!isset($oj->abbr) || $oj->abbr == "")
                        $oj->abbr = self::abbreviate($oj->name, $oj->id);
                    self::$jlist[$oj->id] = $oj;
                    continue;
                }
            }
            error_log("$landmark: bad option " . json_encode($oj));
        }
    }

    static function option_json_list(Conf $c = null) {
        global $Conf, $Opt;
        if (self::$jlist === null) {
            self::$jlist = self::$jmap = [];
            $c = $c ? : $Conf;
            if (($optj = $c->setting_json("options")))
                self::add_json($optj, false, "settings");
            if (isset($Opt["optionsInclude"])) {
                $options_include = $Opt["optionsInclude"];
                if (!is_array($options_include))
                    $options_include = array($options_include);
                foreach ($options_include as $k => $oi) {
                    if (preg_match(',\A\s*\{\s*\",s', $oi))
                        self::add_json($oi, true, "include entry $k");
                    else
                        foreach (expand_includes($oi) as $f)
                            if (($x = file_get_contents($f)))
                                self::add_json($x, true, $f);
                }
            }
            uasort(self::$jlist, ["PaperOption", "compare"]);
        }
        return self::$jlist;
    }

    static function option_ids(Conf $c = null) {
        return array_keys(self::option_json_list($c));
    }

    static function find($id) {
        if (!array_key_exists($id, self::$jmap)) {
            $oj = get(self::option_json_list(), $id);
            self::$jmap[$id] = $oj ? PaperOption::make($oj) : null;
        }
        return self::$jmap[$id];
    }

    static function option_list(Conf $c = null) {
        global $Conf, $Opt;
        if (self::$list === null) {
            self::$list = [];
            foreach (self::option_json_list($c) as $id => $oj)
                self::$list[$id] = self::find($id);
        }
        return self::$list;
    }

    static function nonfixed_option_list(Conf $c = null) {
        if (self::$nonfixed_list === null) {
            self::$nonfixed_list = [];
            foreach (self::option_json_list($c) as $id => $oj)
                if ($id < self::MINFIXEDID)
                    self::$nonfixed_list[$id] = self::find($id);
        }
        return self::$nonfixed_list;
    }

    static function nonfinal_option_list() {
        $list = [];
        foreach (self::option_json_list() as $id => $oj)
            if (!get($oj, "final") && ($o = self::find($id)) && !$o->final)
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
        self::$jmap = [];
    }

    static function count_option_list() {
        return count(self::option_json_list());
    }

    static function find_document($id) {
        if ($id == DTYPE_SUBMISSION)
            return new DocumentPaperOption(array("id" => DTYPE_SUBMISSION, "name" => "Submission", "abbr" => "paper", "type" => null, "position" => -1));
        else if ($id == DTYPE_FINAL)
            return new DocumentPaperOption(array("id" => DTYPE_FINAL, "name" => "Final version", "abbr" => "final", "type" => null, "final" => true, "position" => -1));
        else
            return self::find($id);
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
            if ($oj->abbr === $name)
                return array($id => self::find($id));
            else
                $oabbr[$id] = $oj->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        foreach ($oabbr as $id => &$x)
            $x = self::find($id);
        return $oabbr;
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

    static function type_takes_pdf($type) {
        return $type === "pdf" || $type === "slides";
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
            $o = PaperOption::find($oid);
            if (!$o && !$all)
                continue;
            $needs_data = !$o || $o->needs_data();
            if ($needs_data && !$optdata)
                $optdata = self::load_optdata($prow->paperId);
            $odata = [];
            if ($needs_data)
                foreach ($ovalues as $v)
                    $odata[] = $optdata[$oid . "." . $v];
            $option_array[$oid] = new PaperOptionValue($oid, $o, $ovalues, $odata);
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

    function value_compare($av, $bv) {
        return ($av && $av->value ? 1 : 0) - ($bv && $bv->value ? 1 : 0);
    }

    function echo_editable_html(PaperOptionValue $ov, $reqv, PaperTable $pt) {
        $pt->echo_editable_document($this, $ov->value ? : 0, 0);
        echo "</div>\n\n";
    }

    function parse_request($opt_pj, $qreq, Contact $user, $pj) {
        if ($qreq->_FILES["opt$this->id"])
            return Filer::file_upload_json($qreq->_FILES["opt$this->id"]);
        else if ($qreq["remove_opt$this->id"])
            return null;
        else
            return $opt_pj;
    }

    function unparse_json(PaperOptionValue $ov, PaperStatus $ps, Contact $user = null) {
        if (($doc = $ps->document_to_json($this->id, $ov->value)))
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
            foreach ($v->documents($row) as $d)
                return documentDownload($d, "sdlimg", "", true);
        return "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        foreach ($ov->documents($row) as $d)
            return [documentDownload($d, "sdlimg", htmlspecialchars($this->name)), true];
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
            Ht::entry("opt$this->id", $reqv, ["size" => 8, "onchange" => "hiliter(this)"]),
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
        else if ($v === "" || $v === null)
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
            Ht::textarea("opt$this->id", $reqv, ["class" => "papertext", "rows" => max($this->display_space, 1), "cols" => 60, "onchange" => "hiliter(this)", "spellcheck" => "true"]),
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
        foreach ($ov->documents($pt->prow) as $doc) {
            $oname = "opt" . $this->id . "_" . $doc->paperStorageId;
            echo "<div id=\"removable_$oname\" class=\"foldo\"><table id=\"current_$oname\"><tr>",
                "<td class=\"nw\">", documentDownload($doc, "dlimg", htmlspecialchars($doc->unique_filename)), "</td>",
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
                $attachments[] = Filer::file_upload_json($v);
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
        foreach ($ov->documents($ps->paper_row()) as $doc)
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

    private function unparse_html($row, PaperOptionValue $ov, $no_size) {
        $docs = [];
        foreach ($ov->documents($row) as $d) {
            $name = htmlspecialchars($d->unique_filename);
            $docs[] = documentDownload($d, count($docs) ? "sdlimgsp" : "sdlimg", $name, $no_size);
        }
        return join("<br />", $docs);
    }

    function unparse_column_html(PaperList $pl, $row) {
        $ov = $row->option($this->id);
        return $ov ? $this->unparse_html($row, $ov, true) : "";
    }

    function unparse_page_html($row, PaperOptionValue $ov) {
        return $this->unparse_html($row, $ov, false);
    }
}
