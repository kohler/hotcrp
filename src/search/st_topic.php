<?php
// search/st_topic.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topic_SearchTerm extends SearchTerm {
    /** @var true|list<int> */
    private $topics;
    private $negated;

    /** @param true|list<int> $topics
     * @param bool $negated */
    function __construct($topics, $negated) {
        parent::__construct("topic");
        $this->topics = $topics;
        $this->negated = $negated;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = simplify_whitespace($word);
        $tlist = $srch->conf->topic_set()->find_all($word);
        if (empty($tlist)) {
            $srch->lwarning($sword, "<0>Topic ‘{$word}’ not found");
            return new False_SearchTerm;
        } else if ($tlist === [-1]) {
            return new Topic_SearchTerm(true, false);
        } else if ($tlist[0] === 0) {
            return new Topic_SearchTerm(count($tlist) === 1 ? true : array_slice($tlist, 1), true);
        } else {
            return new Topic_SearchTerm($tlist, false);
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $tm = "";
        if (is_array($this->topics)) {
            $tm = " and topicId in (" . join(",", $this->topics) . ")";
        }
        $t = "exists (select * from PaperTopic where paperId=Paper.paperId$tm)";
        if ($this->negated) {
            $t = "not $t";
        }
        return $t;
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->topics === true) {
            $v = $row->has_topics();
        } else if (count($this->topics) === 1) {
            $v = in_array($this->topics[0], $row->topic_list());
        } else {
            $v = !!array_intersect($this->topics, $row->topic_list());
        }
        return $this->negated ? !$v : $v;
    }
    function script_expression(PaperInfo $row) {
        $o = ["type" => "checkboxes", "formid" => "topics", "values" => $this->topics];
        if ($this->negated) {
            $o = ["type" => "not", "child" => [$o]];
        }
        return $o;
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
