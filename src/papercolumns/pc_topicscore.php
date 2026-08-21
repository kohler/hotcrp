<?php
// pc_topicscore.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class TopicScore_PaperColumn extends PaperColumn {
    /** @var Topics_PaperOption */
    private $opt;
    /** @var Contact */
    private $user;
    /** @var ScoreInfo */
    private $statistics;
    /** @var ContactInfo */
    private $observer;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->opt = $conf->option_by_id(PaperOption::TOPICSID);
        if (isset($cj->user)) {
            $this->user = $conf->pc_member_by_email($cj->user);
        }
        $this->statistics = new ScoreInfo;
    }
    function prepare(PaperList $pl, $visible) {
        $this->user = $this->user ?? $pl->reviewer_user();
        if (!$pl->conf->has_topics()
            || !$pl->user->isPC
            || !$pl->user->can_view_some_option($this->opt)) {
            return false;
        }
        if ($pl->user->contactId !== $this->user->contactId
            && !$pl->user->privChair) {
            $this->observer = $pl->user;
        }
        $pl->qopts["topics"] = 1;
        return true;
    }
    private function value(PaperInfo $row) {
        if ((!$this->observer
             || $this->observer->allow_view_preference($row))
            && $this->opt->test_exists($row)) {
            return $row->topic_interest_score($this->user);
        }
        return null;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return ($this->value($a) ?? -10000) <=> ($this->value($b) ?? -10000);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $v = $this->value($row);
        $this->statistics->add_overriding($v, $pl->overriding);
        return $v !== null ? self::unparse_value($v) : "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->value($this->user);
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $this->value($this->user);
    }
    function has_statistics() {
        return true;
    }
    function statistics() {
        return $this->statistics;
    }

    /** @param int|float $v
     * @return string */
    static function unparse_value($v) {
        if (!is_int($v)) {
            if (abs(fmod($v, 1)) >= 0.01) {
                return $v < 0 ? sprintf("−%.2f", -$v) : sprintf("%.2f", $v);
            }
            $v = (int) round($v);
        }
        return $v < 0 ? "−" /*U+2122*/ . (-$v) : (string) $v;
    }

    static function expand($name, XtParams $xtp, $xfj, $m) {
        $user = $xtp->user;
        if (!($fj = (array) $xtp->conf->basic_paper_column("topicscore", $user))) {
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
            PaperColumn::column_error_at($xtp, $name, "<0>PC member ‘{$m[1]}’ not found");
        }
        return $rs;
    }
}
