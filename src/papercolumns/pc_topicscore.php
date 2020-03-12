<?php
// pc_topicscore.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class TopicScore_PaperColumn extends PaperColumn {
    private $contact;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        if (!$pl->conf->has_topics()
            || !$pl->user->isPC
            || ($this->contact->contactId !== $pl->user->contactId
                && !$pl->user->is_manager())) {
            return false;
        }
        if ($visible) {
            $pl->qopts["topics"] = 1;
        }
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $at = $a->topic_interest_score($this->contact);
        $bt = $b->topic_interest_score($this->contact);
        return $at < $bt ? 1 : ($at == $bt ? 0 : -1);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return htmlspecialchars($row->topic_interest_score($this->contact));
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->topic_interest_score($this->contact);
    }

    static function expand($name, $user, $xfj, $m) {
        if (!($fj = (array) $user->conf->basic_paper_column("topicscore", $user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $user)->ids as $cid) {
            $u = $user->conf->cached_user_by_id($cid);
            $fj["name"] = "topicscore:" . $u->email;
            $fj["user"] = $u->email;
            $fj["title"] = $user->reviewer_text_for($u) . " topic score";
            $fj["title_html"] = $user->reviewer_html_for($u) . " topic score";
            $rs[] = (object) $fj;
        }
        if (empty($rs)) {
            $user->conf->xt_factory_error("No PC member matches “" . htmlspecialchars($m[1]) . "”.");
        }
        return $rs;
    }
}
