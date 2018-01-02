<?php
// pc_topics.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Topics_PaperColumn extends PaperColumn {
    private $interest_contact;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_topics())
            return false;
        if ($visible)
            $pl->qopts["topics"] = 1;
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
        return !isset($row->topicIds) || $row->topicIds == "";
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (!($tmap = $row->named_topic_map()))
            return "";
        $out = $interests = [];
        if ($this->interest_contact)
            $interests = $this->interest_contact->topic_interest_map();
        $sep = rtrim($row->conf->topic_separator()) . '</span> ';
        foreach ($tmap as $tid => $tname) {
            if (!empty($out))
                $out[] = $sep;
            $t = '<span class="topicsp';
            if (($i = get($interests, $tid)))
                $t .= ' topic' . $i;
            if (strlen($tname) <= 50)
                $t .= ' nw';
            $out[] = $t . '">' . htmlspecialchars($tname);
        }
        $out[] = '</span>';
        return join("", $out);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->unparse_topics_text();
    }
}
