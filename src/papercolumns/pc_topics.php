<?php
// pc_topics.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Topics_PaperColumn extends PaperColumn {
    private $interest_contact;
    private $need_has = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics())
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
        else
            $this->need_has = true;
        // only managers can see other users’ topic interests
        $this->interest_contact = $pl->reviewer_user();
        if ($this->interest_contact->contactId !== $pl->user->contactId
            && !$pl->user->is_manager())
            $this->interest_contact = null;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Topics";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        if (!isset($row->topicIds) && $this->need_has) {
            $this->has_content = $this->has_content
                || !!$pl->conf->fetch_ivalue("select exists(select * from PaperTopic where paperId?a) from dual", $pl->rowset()->paper_ids());
            $this->need_has = false;
        }
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (!($tmap = $row->named_topic_map()))
            return "";
        $out = $interests = [];
        if ($this->interest_contact)
            $interests = $this->interest_contact->topic_interest_map();
        foreach ($tmap as $tid => $tname) {
            $t = '<li class="pl_topicti';
            if (!empty($interests) && ($i = get($interests, $tid)))
                $t .= ' topic' . $i;
            $out[] = $t . '">' . htmlspecialchars($tname) . '</li>';
        }
        return '<ul class="pl_topict">' . join("", $out) . '</ul>';
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_text();
    }
}
