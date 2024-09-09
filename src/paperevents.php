<?php
// paperevents.php -- HotCRP paper events
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class PaperEvent implements JsonSerializable {
    /** @var PaperInfo */
    public $prow;
    /** @var ReviewInfo */
    public $rrow;
    /** @var CommentInfo */
    public $crow;
    /** @var int */
    public $eventTime;

    /** @param ?ReviewInfo $rrow
     * @param ?CommentInfo $crow */
    function __construct(PaperInfo $prow, $rrow, $crow) {
        $this->prow = $prow;
        if ($rrow !== null) {
            $this->rrow = $rrow;
            $this->eventTime = (int) $rrow->reviewModified;
        } else if ($crow !== null) {
            $this->crow = $crow;
            $this->eventTime = (int) $crow->timeModified;
        }
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [
            "object" => "event",
            "pid" => $this->prow->paperId,
            "time" => $this->eventTime
        ];
        if ($this->rrow) {
            $j["review"] = $this->rrow;
        } else if ($this->crow) {
            $j["comment"] = $this->crow;
        }
        return $j;
    }
}

class PaperEvents {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var bool */
    private $all_papers = false;
    /** @var PaperInfoSet */
    private $prows;

    /** @var float */
    private $start;
    /** @var int */
    private $limit;
    /** @var list<ReviewInfo> */
    private $rrows = [];
    /** @var int */
    private $ridx;
    /** @var list<CommentInfo> */
    private $crows = [];
    /** @var int */
    private $cidx;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;

        if (($user->defaultWatch & Contact::WATCH_REVIEW_ALL) != 0
            || ($user->is_track_manager()
                && ($user->defaultWatch & Contact::WATCH_REVIEW_MANAGED) != 0)) {
            $this->all_papers = true;
            $this->prows = new PaperInfoSet;
        } else {
            // Papers (perhaps limited to those being watched) whose reviews
            // are viewable.
            $this->prows = $user->paper_set(["myWatching" => true, "myWatch" => true]);
        }
    }

    /** @param ReviewInfo|CommentInfo $xrow */
    private function initial_wheres($xrow, $key) {
        $where = $qv = [];
        if (!$this->all_papers) {
            $where[] = "paperId?a";
            $qv[] = $this->prows->paper_ids();
        }
        if ($xrow) {
            $where[] = "({$key}<? or ({$key}=? and (paperId>? or (paperId=? and contactId>?))))";
            array_push($qv, $xrow->$key, $xrow->$key, $xrow->paperId, $xrow->paperId, $xrow->contactId);
        } else {
            $where[] = "{$key}<?";
            $qv[] = $this->start;
        }
        return [$where, $qv];
    }

    private function more_reviews() {
        $rrow = $this->rrows[$this->ridx - 1] ?? null;
        list($where, $qv) = $this->initial_wheres($rrow, "reviewModified");
        $where[] = "reviewSubmitted>0";
        $q = "select * from PaperReview where " . join(" and ", $where) . " order by reviewModified desc, paperId asc, contactId asc limit {$this->limit}";

        $this->rrows = [];
        $result = $this->conf->qe_apply($q, $qv);
        while (($rrow = ReviewInfo::fetch($result, null, $this->conf))) {
            $this->rrows[] = $rrow;
        }
        Dbl::free($result);
        $this->ridx = empty($this->rrows) ? -1 : 0;
    }

    private function more_comments() {
        $crow = $this->crows[$this->cidx - 1] ?? null;
        list($where, $qv) = $this->initial_wheres($crow, "timeModified");
        $q = "select * from PaperComment where " . join(" and ", $where) . " order by timeModified desc, paperId asc, contactId asc limit {$this->limit}";

        $this->crows = [];
        $result = $this->conf->qe_apply($q, $qv);
        while (($crow = CommentInfo::fetch($result, null, $this->conf))) {
            $this->crows[] = $crow;
        }
        Dbl::free($result);
        $this->cidx = empty($this->crows) ? -1 : 0;
    }

    private function refresh() {
        if ($this->ridx === count($this->rrows)) {
            $this->more_reviews();
        }
        if ($this->cidx === count($this->crows)) {
            $this->more_comments();
        }
        $need = [];
        foreach ($this->rrows as $rrow) {
            if (!$this->prows->get($rrow->paperId))
                $need[$rrow->paperId] = true;
        }
        foreach ($this->crows as $crow) {
            if (!$this->prows->get($crow->paperId))
                $need[$crow->paperId] = true;
        }
        if (!empty($need)) {
            $this->prows->add_result($this->conf->paper_result(["paperId" => array_keys($need), "myWatch" => true], $this->user), $this->user);
        }
    }

    /** @return ?PaperEvent */
    private function make_revt(ReviewInfo $rrow) {
        if (($prow = $this->prows->get($rrow->paperId))
            && $this->user->can_view_paper($prow)
            && !$this->user->act_author_view($prow)
            && $this->user->following_reviews($prow, 0)) {
            $rrow->set_prow($prow);
            if ($this->user->can_view_review($prow, $rrow)) {
                return new PaperEvent($prow, $rrow, null);
            }
        }
        return null;
    }

    /** @return ?PaperEvent */
    private function make_cevt(CommentInfo $crow) {
        if (($prow = $this->prows->get($crow->paperId))
            && $this->user->can_view_paper($prow)
            && !$this->user->act_author_view($prow)
            && $this->user->following_reviews($prow, $crow->commentType)
            && $this->user->can_view_comment($prow, $crow)) {
            $crow->set_prow($prow);
            return new PaperEvent($prow, null, $crow);
        }
        return null;
    }

    /** @param ReviewInfo $rrow
     * @param CommentInfo $crow
     * @return bool */
    static private function revt_precedes_cevt($rrow, $crow) {
        $tcmp = $rrow->reviewModified <=> $crow->timeModified;
        return $tcmp > 0 || ($tcmp === 0 && $rrow->paperId <= $crow->paperId);
    }

    /** @param int|float $start */
    function reset($start) {
        $this->rrows = $this->crows = [];
        $this->ridx = $this->cidx = 0;
        $this->start = $start;
        $this->limit = 25;
    }

    /** @return ?PaperEvent */
    function next_event() {
        while (true) {
            if ($this->ridx === count($this->rrows)
                || $this->cidx === count($this->crows)) {
                $this->refresh();
            }
            $rrow = $this->rrows[$this->ridx] ?? null;
            $crow = $this->crows[$this->cidx] ?? null;
            if (!$rrow && !$crow) {
                return null;
            }
            if ($rrow && (!$crow || self::revt_precedes_cevt($rrow, $crow))) {
                ++$this->ridx;
                if (($revt = $this->make_revt($rrow))) {
                    return $revt;
                }
            } else {
                ++$this->cidx;
                if (($cevt = $this->make_cevt($crow))) {
                    return $cevt;
                }
            }
        }
    }

    /** @param int|float $start
     * @param int $limit
     * @return list<PaperEvent> */
    function events($start, $limit) {
        $this->reset($start);
        $this->limit = $limit;
        $last_time = null;
        $evts = [];
        while (($evt = $this->next_event())
               && (count($evts) < $limit || $evt->eventTime === $last_time)) {
            $evts[] = $evt;
            $last_time = $evt->eventTime;
        }
        return $evts;
    }
}
