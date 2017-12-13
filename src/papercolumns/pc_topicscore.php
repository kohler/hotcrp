<?php
// pc_topicscore.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class TopicScore_PaperColumn extends PaperColumn {
    private $contact;
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        if (!$pl->conf->has_topics()
            || !$pl->user->isPC
            || ($this->contact->contactId !== $pl->user->contactId
                && !$pl->user->is_manager()))
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        return $b->topic_interest_score($this->contact) - $a->topic_interest_score($this->contact);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Topic score" : "Topic<br />score";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->topic_interest_score($this->contact);
    }
}
