<?php
// pc_topicscore.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TopicScore_PaperColumn extends PaperColumn {
    /** @var Contact */
    private $contact;
    /** @var ScoreInfo */
    private $statistics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (isset($cj->user)) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
        $this->statistics = new ScoreInfo;
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ?? $pl->reviewer_user();
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
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $b->topic_interest_score($this->contact) <=> $a->topic_interest_score($this->contact);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $row->topic_interest_score($this->contact);
        $this->statistics->add($v);
        return htmlspecialchars((string) $v);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $row->topic_interest_score($this->contact);
    }
    function has_statistics() {
        return true;
    }
    function statistic_html(PaperList $pl, $stat) {
        $v = $this->statistics->statistic($stat);
        return is_int($v) ? (string) $v : sprintf("%.2f", $v);
    }

    static function expand($name, Contact $user, $xfj, $m) {
        if (!($fj = (array) $user->conf->basic_paper_column("topicscore", $user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $user)->users() as $u) {
            $fj["name"] = "topicscore:" . $u->email;
            $fj["user"] = $u->email;
            $fj["title"] = $user->reviewer_text_for($u) . " topic score";
            $fj["title_html"] = $user->reviewer_html_for($u) . " topic score";
            $rs[] = (object) $fj;
        }
        if (empty($rs)) {
            PaperColumn::column_error($user, "<0>PC member ‘{$m[1]}’ not found");
        }
        return $rs;
    }
}
