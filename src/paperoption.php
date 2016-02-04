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
    private $_documents = null;

    public function __construct(PaperOption $o) {
        $this->id = $o->id;
        $this->option = $o;
    }
    public function documents($prow) {
        assert($this->option->has_document());
        if ($this->_documents === null) {
            $this->_documents = $by_unique_filename = array();
            $docclass = null;
            foreach ($this->values as $docid)
                if ($docid > 1 && ($d = paperDocumentData($prow, $this->id, $docid))) {
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
}

class PaperOption {
    public $id;
    public $name;
    public $type; // checkbox, selector, radio, numeric, text,
                  // pdf, slides, video, attachments
    public $abbr;
    public $description;
    public $position;
    public $final;
    public $visibility; // "rev", "nonblind", "admin"
    private $display;
    public $display_space;
    public $selector;

    static private $list = null;

    const DISP_TOPICS = 0;
    const DISP_PROMINENT = 1;
    const DISP_SUBMISSION = 2;
    const DISP_DEFAULT = 3;
    static private $display_map = [
        "default" => self::DISP_DEFAULT, "submission" => self::DISP_SUBMISSION,
        "topics" => self::DISP_TOPICS, "prominent" => self::DISP_PROMINENT
    ];
    static private $display_rmap = null;

    function __construct($args) {
        if (is_object($args))
            $args = get_object_vars($args);
        $this->id = (int) $args["id"];
        $this->name = $args["name"];
        $this->type = $args["type"];
        if (!($this->abbr = get_s($args, "abbr")))
            $this->abbr = self::abbreviate($this->name, $this->id);
        $this->description = get_s($args, "description");
        $this->position = get_i($args, "position");
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

        if (($x = get($args, "display_space")))
            $this->display_space = (int) $x;
        $this->selector = get($args, "selector");
    }

    static function compare($a, $b) {
        if ($a->position != $b->position)
            return $a->position - $b->position;
        else
            return $a->id - $b->id;
    }

    static function option_list(Conf $c = null) {
        global $Conf;
        if (self::$list === null) {
            self::$list = array();
            $c = $c ? : $Conf;
            if (($optj = $c->setting_json("options"))) {
                foreach ($optj as $j)
                    self::$list[$j->id] = PaperOption::make($j);
                uasort(self::$list, array("PaperOption", "compare"));
            }
        }
        return self::$list;
    }

    static function make($args) {
        if (is_object($args))
            $args = get_object_vars($args);
        $type = get($args, "type");
        if ($type === "checkbox")
            return new CheckboxPaperOption($args);
        else if ($type === "radio" || $type === "selector")
            return new SelectorPaperOption($args);
        else if ($type === "numeric")
            return new NumericPaperOption($args);
        else if ($type === "text")
            return new TextPaperOption($args);
        else if ($type === "pdf" || $type === "slides" || $type === "video")
            return new DocumentPaperOption($args);
        else if ($type === "attachments")
            return new AttachmentsPaperOption($args);
        else
            return new PaperOption($args);
    }

    static function invalidate_option_list() {
        self::$list = null;
    }

    static function find($id) {
        if (self::$list === null)
            self::option_list();
        return get(self::$list, $id);
    }

    static function find_document($id) {
        if ($id == DTYPE_SUBMISSION)
            return new DocumentPaperOption(array("id" => DTYPE_SUBMISSION, "name" => "Submission", "abbr" => "paper", "type" => null));
        else if ($id == DTYPE_FINAL)
            return new DocumentPaperOption(array("id" => DTYPE_FINAL, "name" => "Final version", "abbr" => "final", "type" => null));
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
        if (self::$list === null)
            self::option_list();
        if ($name === "" || $name === "none")
            return array();
        else if ($name === "any")
            return self::$list;
        if (substr($name, 0, 4) === "opt-")
            $name = substr($name, 4);
        $oabbr = array();
        foreach (self::$list as $o)
            if ($o->abbr === $name)
                return array($o->id => $o);
            else
                $oabbr[$o->id] = $o->abbr;
        $oabbr = Text::simple_search($name, $oabbr, Text::SEARCH_CASE_SENSITIVE);
        foreach ($oabbr as $id => &$x)
            $x = self::$list[$id];
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

    function has_selector() {
        return false;
    }

    function is_document() {
        return false;
    }

    function has_document() {
        return false;
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

    function display() {
        if ($this->display === self::DISP_DEFAULT)
            return $this->has_document() ? self::DISP_PROMINENT : self::DISP_TOPICS;
        else
            return $this->display;
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

    private static function sort_multiples($o, $ox) {
        if ($o->type === "attachments")
            array_multisort($ox->data, SORT_NUMERIC, $ox->values);
    }

    private static function load_optdata($pid) {
        $result = Dbl::qe("select optionId, value, data from PaperOption where paperId=?", $pid);
        $optdata = array();
        while (($row = edb_row($result)))
            $optdata[$row[0] . "." . $row[1]] = $row[2];
        Dbl::free($result);
        return $optdata;
    }

    static function parse_paper_options($prow) {
        if (!$prow)
            return 0;
        $options = self::option_list();
        $prow->option_array = array();
        if (!count($options) || get($prow, "optionIds") === "")
            return 0;

        $optsel = array();
        if (property_exists($prow, "optionIds")) {
            preg_match_all('/(\d+)#(\d+)/', $prow->optionIds, $m);
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

        foreach ($options as $o)
            if (isset($optsel[$o->id])) {
                $ox = new PaperOptionValue($o);
                if ($o->needs_data() && !$optdata)
                    $optdata = self::load_optdata($prow->paperId);
                $ox->values = $optsel[$o->id];
                if ($o->takes_multiple()) {
                    if ($o->needs_data()) {
                        $ox->data = array();
                        foreach ($ox->values as $v)
                            $ox->data[] = $optdata[$o->id . "." . $v];
                    }
                    self::sort_multiples($o, $ox);
                } else {
                    $ox->value = $optsel[$o->id][0];
                    if ($o->needs_data())
                        $ox->data = $optdata[$o->id . "." . $ox->value];
                }
                $prow->option_array[$o->id] = $ox;
            }

        return count($prow->option_array);
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

    function unparse_column_html($pl, $row) {
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

    function unparse_column_html($pl, $row) {
        $v = $row->option($this->id);
        return $v && $v->value ? "✓" : "";
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

    function unparse_column_html($pl, $row) {
        $v = $row->option($this->id);
        return isset($this->selector[$v]) ? htmlspecialchars($this->selector[$v]) : "";
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

    function unparse_column_html($pl, $row) {
        if (($v = $row->option($this->id)))
            foreach ($v->documents($row) as $d)
                return documentDownload($d, "sdlimg", "", true);
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

    function unparse_column_html($pl, $row) {
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

    function unparse_column_html($pl, $row) {
        $v = $row->option($this->id);
        if ($v && $v->data !== null && $v->data !== "")
            return '<div class="format0">' . Ht::link_urls(htmlspecialchars($v->data)) . '</div>';
        else
            return "";
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

    function unparse_column_html($pl, $row) {
        $docs = [];
        if (($v = $row->option($this->id)))
            foreach ($v->documents($row) as $d) {
                $name = htmlspecialchars($d->unique_filename);
                $docs[] = documentDownload($d, count($docs) ? "sdlimgsp" : "sdlimg", $name, true);
            }
        return join("<br>", $docs);
    }
}
