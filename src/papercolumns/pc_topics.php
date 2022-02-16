<?php
// pc_topics.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Topics_PaperColumn extends PaperColumn {
    /** @var ?Contact */
    private $interest_contact;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics()) {
            return false;
        }
        if ($visible) {
            $pl->qopts["topics"] = 1;
        }
        // only managers can see other users’ topic interests
        $this->interest_contact = $pl->reviewer_user();
        if ($this->interest_contact->contactId !== $pl->user->contactId
            && !$pl->user->is_manager()) {
            $this->interest_contact = null;
        }
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->topicIds === "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->conf->topic_set()->unparse_list_html($row->topic_list(), $this->interest_contact ? $this->interest_contact->topic_interest_map() : null);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_text();
    }
}
