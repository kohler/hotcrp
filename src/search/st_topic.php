<?php
// search/st_topic.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Topic_SearchTerm extends SearchTerm {
    private $topics;

    function __construct($topics) {
        parent::__construct("topic");
        $this->topics = $topics;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $value = null;
        $lword = simplify_whitespace(strtolower($word));
        if ($lword === "none" || $lword === "any")
            $value = $lword === "any";
        else if ($lword === "") {
            $srch->warn("Topic missing.");
            return new False_SearchTerm;
        } else {
            $tids = array();
            foreach ($srch->conf->topic_map() as $tid => $tname)
                if (strstr(strtolower($tname), $lword) !== false)
                    $tids[] = $tid;
            if (empty($tids))
                $srch->warn("“" . htmlspecialchars($lword) . "” does not match any defined paper topic.");
            $value = $tids;
        }
        return new Topic_SearchTerm($value);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $tm = "";
        if ($this->topics === [])
            return "false";
        else if (is_array($this->topics))
            $tm = " and topicId in (" . join(",", $this->topics) . ")";
        $t = "exists (select * from PaperTopic where paperId=Paper.paperId$tm)";
        if ($this->topics === false)
            $t = "not $t";
        return $t;
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if (is_bool($this->topics))
            return $row->has_topics() === $this->topics;
        else {
            foreach ($this->topics as $tid)
                if (in_array($tid, $row->topic_list()))
                    return true;
            return false;
        }
    }
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        return (object) ["type" => "topic", "topics" => $this->topics];
    }
}
