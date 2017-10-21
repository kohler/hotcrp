<?php
// search/st_topic.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
        $thistab = "Topic_" . count($sqi->tables);
        $joiner = "";
        if (!is_array($this->topics))
            $thistab = "AnyTopic";
        else if (empty($this->topics))
            $joiner = "false";
        else
            $joiner = "topicId in (" . join(",", $this->topics) . ")";
        $sqi->add_table($thistab, ["left join", "PaperTopic", $joiner]);
        return "$thistab.topicId is " . ($this->topics === false ? "null" : "not null");
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
}
