<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperOption {
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

    static function option_list() {
        global $Conf;
        if (self::$list === null) {
            self::$list = array();
            if (($optj = $Conf->setting_json("options"))) {
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
        if ((string) $name == (string) DTYPE_SUBMISSION
            || strcasecmp($name, "paper") == 0
            || strcasecmp($name, "submission") == 0)
            return array(DTYPE_SUBMISSION => self::find_document(DTYPE_SUBMISSION));
        else if ((string) $name == (string) DTYPE_FINAL
                 || strcasecmp($name, "final") == 0)
            return array(DTYPE_FINAL => self::find_document(DTYPE_FINAL));
        if (self::$list === null)
            self::option_list();
        if (($o = @self::$list[$name]))
            return array($o->id => $o);
        $name = @strtolower($name);
        if ($name === "" || $name === "none")
            return array();
        else if ($name === "any")
            return self::$list;
        if (substr($name, 0, 4) === "opt-")
            $name = substr($name, 4);
        foreach (self::$list as $o)
            if ($o->abbr == $name)
                return array($o->id => $o);
        $rewords = array();
        foreach (preg_split('/[^a-z_0-9*]+/', $name) as $word)
            if ($word !== "")
                $rewords[] = str_replace("*", ".*", $word);
        $re = array(',\A\b' . join('\b.*\b', $rewords) . '\b,',
                    ',\b' . join('.*\b', $rewords) . ',');
        $matches = array();
        $matchscore = count($re) - 1;
        foreach (self::$list as $o)
            for ($i = 0; $i <= $matchscore; ++$i)
                if (preg_match($re[$i], $o->abbr)) {
                    if ($i < $matchscore) {
                        $matches = array();
                        $matchscore = $i;
                    }
                    $matches[$o->id] = $o;
                }
        return $matches;
    }

    static function abbreviate($name, $id) {
        $abbr = strtolower(UnicodeHelper::deaccent($name));
        $abbr = preg_replace('/[^a-z_0-9]+/', "-", $abbr);
        $abbr = preg_replace('/^-+|-+$/', "", $abbr);
        if (preg_match('/\A(?:|submission|paper|final|opt\d+|\d+)\z/', $abbr))
            $abbr = "opt$id";
        return $abbr;
    }

    static function type_has_selector($type) {
        return $type == "radio" || $type == "selector";
    }

    function has_selector() {
        return self::type_has_selector($this->type);
    }

    static function type_takes_pdf($type) {
        return $type == "pdf" || $type == "slides";
    }

    function is_document() {
        return $this->type == "pdf" || $this->type == "slides"
            || $this->type == "video";
    }

    function value_is_document() {
        return $this->is_document() || $this->type == "attachments";
    }

    function needs_data() {
        return $this->type == "text" || $this->type == "attachments";
    }

    function takes_multiple() {
        return $this->type == "attachments";
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
        if (@$this->visibility && $this->visibility != "rev")
            $j->visibility = $this->visibility;
        if (@$this->display_space)
            $j->display_space = $this->display_space;
        if (@$this->selector)
            $j->selector = $this->selector;
        return $j;
    }

    private static function sort_multiples($o, $ox) {
        if ($o->type == "attachments")
            array_multisort($ox->data, SORT_NUMERIC, $ox->values);
    }

    static function parse_paper_options($prow) {
        global $Conf;
        if (!$prow)
            return 0;
        $options = self::option_list();
        $prow->option_array = array();
        if (!count($options) || !@$prow->optionIds)
            return 0;

        preg_match_all('/(\d+)#(\d+)/', $prow->optionIds, $m);
        $optsel = array();
        for ($i = 0; $i < count($m[1]); ++$i)
            arrayappend($optsel[$m[1][$i]], (int) $m[2][$i]);
        $optdata = null;

        foreach ($options as $o)
            if (isset($optsel[$o->id])) {
                $ox = (object) array("id" => $o->id, "option" => $o);
                if ($o->needs_data() && !$optdata) {
                    $optdata = array();
                    $result = $Conf->qe("select optionId, value, data from PaperOption where paperId=$prow->paperId");
                    while (($row = edb_row($result)))
                        $optdata[$row[0] . "." . $row[1]] = $row[2];
                }
                if ($o->takes_multiple()) {
                    $ox->values = $optsel[$o->id];
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
