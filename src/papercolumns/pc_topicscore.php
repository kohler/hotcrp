<?php
// pc_topicscore.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class TopicScore_PaperColumn extends PaperColumn {
    private $contact;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user))
            $this->contact = $conf->pc_member_by_email($cj->user);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
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
        $at = $a->topic_interest_score($this->contact);
        $bt = $b->topic_interest_score($this->contact);
        return $at < $bt ? 1 : ($at == $bt ? 0 : -1);
    }
    function header(PaperList $pl, $is_text) {
        if ($this->contact === $pl->user)
            return $is_text ? "Topic score" : "Topic<br />score";
        else if ($is_text)
            return $pl->user->name_text_for($this->contact) . " topic score";
        else
            return $pl->user->name_html_for($this->contact) . "<br />topic score";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->topic_interest_score($this->contact);
    }

    static function expand($name, Conf $conf, $xfj, $m) {
        if (!($fj = (array) $conf->basic_paper_column("topicscore", $conf->xt_user)))
            return null;
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $conf->xt_user)->ids as $cid) {
            $u = $conf->cached_user_by_id($cid);
            $fj["name"] = "topicscore:" . $u->email;
            $fj["user"] = $u->email;
            $rs[] = (object) $fj;
        }
        if (empty($rs))
            $conf->xt_factory_error("No PC member matches “" . htmlspecialchars($m[1]) . "”.");
        return $rs;
    }
}
