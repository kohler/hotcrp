<?php
// search/st_topic.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topic_SearchTerm extends SearchTerm {
    /** @var true|list<int> */
    private $topics;
    private $negated;

    /** @param true|list<int> $topics */
    function __construct($topics, $negated) {
        parent::__construct("topic");
        $this->topics = $topics;
        $this->negated = $negated;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $value = null;
        $negated = false;
        $word = simplify_whitespace($word);
        if (strcasecmp($word, "any") === 0) {
            $value = true;
        } else if (strcasecmp($word, "none") === 0) {
            $value = true;
            $negated = true;
        } else if ($word === "") {
            $srch->lwarning($sword, "<0>Topic required");
            return new False_SearchTerm;
        } else {
            $tam = $srch->conf->topic_abbrev_matcher();
            $value = [];
            $pword = "";
            if (($colon = strpos($word, ":")) !== false) {
                $pword = ltrim(substr($word, $colon + 1));
            }
            if (strcasecmp($pword, "any") === 0
                && ($value = $tam->find_all(substr($word, 0, $colon)))) {
            } else if (strcasecmp($pword, "none") === 0
                       && ($value = $tam->find_all(substr($word, 0, $colon)))) {
                $negated = true;
            } else {
                $value = $tam->find_all($word);
            }
            if (empty($value)) {
                $srch->lwarning($sword, "<0>Topic ‘{$word}’ not found");
            }
        }
        return new Topic_SearchTerm($value, $negated);
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $tm = "";
        if ($this->topics === []) {
            return "false";
        } else if (is_array($this->topics)) {
            $tm = " and topicId in (" . join(",", $this->topics) . ")";
        }
        $t = "exists (select * from PaperTopic where paperId=Paper.paperId$tm)";
        if ($this->negated) {
            $t = "not $t";
        }
        return $t;
    }
    function test(PaperInfo $row, $rrow) {
        if ($this->topics === []) {
            return false;
        } else if ($this->topics === true) {
            $v = $row->has_topics();
        } else {
            $v = !!array_intersect($this->topics, $row->topic_list());
        }
        return $this->negated ? !$v : $v;
    }
    function script_expression(PaperInfo $row) {
        $o = ["type" => "topic", "topics" => $this->topics];
        if ($this->negated) {
            $o = ["type" => "not", "child" => [$o]];
        }
        return $o;
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
