<?php
// searchexample.php -- HotCRP helper class for search examples
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SearchExample {
    /** @var ?PaperOption */
    public $opt;
    /** @var ?ReviewField */
    public $rf;
    /** @var string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $category;
    /** @var ?bool */
    public $primary_only;
    /** @var list<string|FmtArg> */
    public $arguments;
    /** @var ?list<string> */
    public $hints;

    const HELP = 0;
    const COMPLETION = 1;

    /** @param PaperOption|ReviewField $field
     * @param string $q
     * @param string $description
     * @param string|FmtArg ...$arguments */
    function __construct($field, $q, $description = "", ...$arguments) {
        if ($field instanceof PaperOption) {
            $this->opt = $field;
        } else if ($field instanceof ReviewField) {
            $this->rf = $field;
        }
        $this->q = $q;
        $this->description = $description;
        $this->arguments = $arguments;
        if ($description !== "" && !Ftext::is_ftext($description)) {
            error_log(debug_string_backtrace());
        }
    }

    /** @param string $category
     * @return $this */
    function category($category) {
        $this->category = $category;
        return $this;
    }

    /** @param bool $primary_only
     * @return $this */
    function primary_only($primary_only) {
        $this->primary_only = $primary_only;
        return $this;
    }

    /** @param string $t
     * @return $this */
    function hint($t) {
        $this->hints[] = $t;
        return $this;
    }

    /** @return string */
    function expanded_query() {
        if (strpos($this->q, "{") === false) {
            return $this->q;
        }
        return Fmt::simple($this->q, ...$this->all_arguments());
    }

    /** @return list<string|FmtArg> */
    function all_arguments() {
        $args = $this->arguments;
        if ($this->opt) {
            $args[] = new FmtArg("title", $this->opt->title(), 0);
            $args[] = new FmtArg("keyword", $this->opt->search_keyword(), 0);
        }
        if ($this->rf) {
            $args[] = new FmtArg("title", $this->rf->name, 0);
            $args[] = new FmtArg("keyword", $this->rf->search_keyword(), 0);
        }
        return $args;
    }

    /** @param list<SearchExample> &$exs
     * @param SearchExample $match
     * @return list<SearchExample> */
    static function remove_category(&$exs, $match) {
        $ret = [];
        for ($i = 0; $i !== count($exs); ) {
            if (($match->description && $exs[$i]->description === $match->description)
                || ($match->category && $exs[$i]->category === $match->category)) {
                $ret[] = $exs[$i];
                array_splice($exs, $i, 1);
            } else {
                ++$i;
            }
        }
        return $ret;
    }
}
