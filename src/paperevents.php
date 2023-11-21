<?php
// paperevents.php -- HotCRP paper events
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperEvent {
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

    /** @var int */
    private $limit;
    /** @var null|false|float|array{int|float,int|float,int|float} */
    private $rposition;
    /** @var list<ReviewInfo> */
    private $rrows = [];
    /** @var ?PaperEvent */
    private $cur_rrow;
    /** @var null|false|float|array{int|float,int|float,int|float} */
    private $cposition;
    /** @var list<CommentInfo> */
    private $crows = [];
    /** @var ?PaperEvent */
    private $cur_crow;

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

    private function initial_wheres($pos, $key) {
        $where = $qv = [];
        if (!$this->all_papers) {
            $where[] = "paperId?a";
            $qv[] = $this->prows->paper_ids();
        }
        if (is_array($pos)) {
            $where[] = "({$key}<? or ({$key}=? and (paperId>? or (paperId=? and contactId>?))))";
            array_push($qv, $pos[0], $pos[0], $pos[1], $pos[1], $pos[2]);
        } else {
            $where[] = "{$key}<?";
            $qv[] = $pos;
        }
        return [$where, $qv];
    }

    private function more_reviews() {
        list($where, $qv) = $this->initial_wheres($this->rposition, "reviewModified");
        $where[] = "reviewSubmitted>0";
        $q = "select * from PaperReview where " . join(" and ", $where) . " order by reviewModified desc, paperId asc, contactId asc limit {$this->limit}";

        $last = null;
        $result = $this->conf->qe_apply($q, $qv);
        while (($rrow = ReviewInfo::fetch($result, null, $this->conf))) {
            $this->rrows[] = $last = $rrow;
        }
        Dbl::free($result);
        $this->rrows = array_reverse($this->rrows);

        $this->rposition = false;
        if ($last) {
            $this->rposition = [+$last->reviewModified, +$last->paperId, +$last->contactId];
        }
    }

    /** @return ?PaperEvent */
    private function next_review() {
        while (true) {
            if (empty($this->rrows) && $this->rposition !== false) {
                $this->more_reviews();
                $this->load_papers();
            }
            $rrow = array_pop($this->rrows);
            if (!$rrow) {
                return null;
            }
            if (($prow = $this->prows->get($rrow->paperId))
                && $this->user->can_view_paper($prow)
                && !$this->user->act_author_view($prow)
                && $this->user->following_reviews($prow, 0)) {
                $rrow->set_prow($prow);
                if ($this->user->can_view_review($prow, $rrow)) {
                    return new PaperEvent($prow, $rrow, null);
                }
            }
        }
    }

    private function more_comments() {
        list($where, $qv) = $this->initial_wheres($this->cposition, "timeModified");
        $q = "select * from PaperComment where " . join(" and ", $where) . " order by timeModified desc, paperId asc, contactId asc limit {$this->limit}";

        $last = null;
        $result = $this->conf->qe_apply($q, $qv);
        while (($crow = CommentInfo::fetch($result, null, $this->conf))) {
            $this->crows[] = $last = $crow;
        }
        Dbl::free($result);
        $this->crows = array_reverse($this->crows);

        $this->cposition = false;
        if ($last) {
            $this->cposition = [+$last->timeModified, +$last->paperId, +$last->contactId];
        }
    }

    /** @return ?PaperEvent */
    private function next_comment() {
        while (true) {
            if (empty($this->crows) && $this->cposition !== false) {
                $this->more_comments();
                $this->load_papers();
            }
            $crow = array_pop($this->crows);
            if (!$crow) {
                return null;
            }
            if (($prow = $this->prows->get($crow->paperId))
                && $this->user->can_view_paper($prow)
                && !$this->user->act_author_view($prow)
                && $this->user->following_reviews($prow, $crow->commentType)
                && $this->user->can_view_comment($prow, $crow)) {
                $crow->set_prow($prow);
                return new PaperEvent($prow, null, $crow);
            }
        }
    }

    private function load_papers() {
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

    /** @param ?PaperEvent $a
     * @param ?PaperEvent $b */
    static function _activity_compar($a, $b) {
        if (!$a || !$b) {
            return !$a && !$b ? 0 : ($a ? -1 : 1);
        } else if ($a->eventTime != $b->eventTime) {
            return $a->eventTime > $b->eventTime ? -1 : 1;
        } else if ($a->prow->paperId != $b->prow->paperId) {
            return $a->prow->paperId < $b->prow->paperId ? -1 : 1;
        } else if (!$a->rrow !== !$b->rrow) {
            return $a->rrow ? -1 : 1;
        } else if ($a->rrow) {
            return $a->rrow->reviewId <=> $b->rrow->reviewId;
        } else {
            return $a->crow->commentId <=> $b->crow->commentId;
        }
    }

    /** @param int $limit
     * @return list<PaperEvent> */
    function events($starting, $limit) {
        $this->rrows = $this->crows = [];
        $this->cur_rrow = $this->cur_crow = null;
        $this->rposition = $this->cposition = (float) $starting;
        $this->limit = $limit;
        $this->more_reviews();
        $this->more_comments();
        $this->load_papers();
        $last_time = null;
        $events = [];
        while (true) {
            if (!$this->cur_rrow) {
                $this->cur_rrow = $this->next_review();
            }
            if (!$this->cur_crow) {
                $this->cur_crow = $this->next_comment();
            }
            if (!$this->cur_rrow && !$this->cur_crow) {
                return $events;
            }
            if (self::_activity_compar($this->cur_rrow, $this->cur_crow) < 0) {
                $key = "cur_rrow";
            } else {
                $key = "cur_crow";
            }
            $erow = $this->$key;
            if (count($events) >= $limit && $erow->eventTime < $last_time) {
                return $events;
            }
            $events[] = $erow;
            $last_time = $erow->eventTime;
            $this->$key = null;
        }
    }
}
