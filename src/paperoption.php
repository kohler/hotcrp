<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperOptionValue {
    public $id;
    public $option;
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
                    while (@$by_unique_filename[$d->unique_filename]) {
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
    public $type;
    public $abbr;
    public $description;
    public $position;
    public $final;
    public $visibility;
    public $near_submission;
    public $highlight;
    public $display_space;
    public $selector;
    private static $list = null;

    function __construct($args) {
        foreach ($args as $k => $v)
            $this->$k = $v;
        if (!@$this->visibility && @$this->view_type === "pc")
            $this->visibility = "rev";
        else if (!@$this->visibility && @$this->view_type)
            $this->visibility = $this->view_type;
        else if (!@$this->visibility)
            $this->visibility = "rev";
        if (!@$this->abbr)
            $this->abbr = self::abbreviate($this->name, $this->id);
        if (!@$this->description)
            $this->description = "";
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
                    self::$list[$j->id] = new PaperOption($j);
                uasort(self::$list, array("PaperOption", "compare"));
            }
        }
        return self::$list;
    }

    static function invalidate_option_list() {
        self::$list = null;
    }

    static function find($id) {
        if (self::$list === null)
            self::option_list();
        return @self::$list[$id];
    }

    static function find_document($id) {
        if ($id == DTYPE_SUBMISSION)
            return new PaperOption(array("id" => DTYPE_SUBMISSION, "name" => "Submission", "abbr" => "paper", "type" => null));
        else if ($id == DTYPE_FINAL)
            return new PaperOption(array("id" => DTYPE_FINAL, "name" => "Final version", "abbr" => "final", "type" => null));
        else
            return self::find($id);
    }

    static function search($name) {
        $name = @strtolower($name);
        if ((string) $name === (string) DTYPE_SUBMISSION
            || $name === "paper"
            || $name === "submission")
            return array(DTYPE_SUBMISSION => self::find_document(DTYPE_SUBMISSION));
        else if ((string) $name === (string) DTYPE_FINAL
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

    function has_selector() {
        return self::type_has_selector($this->type);
    }

    static function type_takes_pdf($type) {
        return $type === "pdf" || $type === "slides";
    }

    function is_document() {
        return $this->type === "pdf" || $this->type === "slides"
            || $this->type === "video";
    }

    function has_document() {
        return $this->is_document() || $this->type === "attachments";
    }

    function needs_data() {
        return $this->type === "text" || $this->type === "attachments";
    }

    function takes_multiple() {
        return $this->type === "attachments";
    }

    function display_type() {
        if (@$this->near_submission)
            return "near_submission";
        else if (@$this->highlight)
            return "highlight";
        else
            return "normal";
    }

    function unparse() {
        $j = (object) array("id" => (int) $this->id,
                            "name" => $this->name,
                            "abbr" => $this->abbr,
                            "type" => $this->type,
                            "position" => (int) $this->position);
        if (@$this->description)
            $j->description = $this->description;
        if (@$this->final)
            $j->final = true;
        if (@$this->near_submission)
            $j->near_submission = true;
        if (@$this->highlight)
            $j->highlight = true;
        if (@$this->visibility && $this->visibility !== "rev")
            $j->visibility = $this->visibility;
        if (@$this->display_space)
            $j->display_space = $this->display_space;
        if (@$this->selector)
            $j->selector = $this->selector;
        return $j;
    }

    function example_searches() {
        $x = array("has" => array("has:$this->abbr", $this),
                   "yes" => array("{$this->abbr}:yes", $this));
        if ($this->type === "numeric")
            $x["numeric"] = array("{$this->abbr}:>100", $this);
        if ($this->has_selector() && @$this->selector[1]) {
            if (preg_match('/\A\w+\z/', $this->selector[1]))
                $x["selector"] = array("{$this->abbr}:" . strtolower($this->selector[1]), $this);
            else if (!strpos($this->selector[1], "\""))
                $x["selector"] = array("{$this->abbr}:\"{$this->selector[1]}\"", $this);
        } else if ($this->type === "attachments") {
            $x["attachment-count"] = array("{$this->abbr}:>2", $this);
            $x["attachment-filename"] = array("{$this->abbr}:*.gif", $this);
        }
        return $x;
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
        if (!count($options) || @$prow->optionIds === "")
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

}
