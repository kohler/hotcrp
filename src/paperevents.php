<?php
// paperevents.php -- HotCRP paper events
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperEvent {
    public $prow;
    public $rrow;
    public $crow;
    public $eventTime;

    function __construct(PaperInfo $prow, $erow) {
        $this->prow = $prow;
        $erow->prow = $prow;
        if (isset($erow->reviewSubmitted))
            $this->rrow = $erow;
        else
            $this->crow = $erow;
        $this->eventTime = $erow->eventTime;
    }
}

class PaperEvents {
    private $conf;
    private $user;
    private $watch;
    private $all_papers = false;
    private $prows;

    private $limit;
    private $rposition;
    private $rrows;
    private $cur_rrow;
    private $cposition;
    private $crows;
    private $cur_crow;

    function __construct(Contact $user, $watch) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->watch = $watch;

        if (($user->privChair
             || ($user->isPC && $this->conf->setting("pc_seeallrev") > 0))
            && (!$watch
                || ($user->defaultWatch & (WATCHTYPE_REVIEW << WATCHSHIFT_ALLON)))) {
            $this->all_papers = true;
            $this->prows = new PaperInfoSet;
        } else {
            // Papers (perhaps limited to those being watched) whose reviews
            // are viewable.
            $result = $user->paper_result(["watch" => true, "myWatching" => true]);
            $this->prows = PaperInfo::fetch_all($result, $user);
        }
    }

    private function initial_wheres($pos, $key) {
        $where = $qv = [];
        if (!$this->all_papers) {
            $where[] = "paperId?a";
            $qv[] = $this->prows->paper_ids();
        }
        if (is_array($pos)) {
            $where[] = "($key<? or ($key=? and (paperId>? or (paperId=? and contactId>?))))";
            array_push($qv, $pos[0], $pos[0], $pos[1], $pos[1], $pos[2]);
        } else {
            $where[] = "$key<?";
            $qv[] = $pos;
        }
        return [$where, $qv];
    }

    private function more_reviews() {
        list($where, $qv) = $this->initial_wheres($this->rposition, "reviewModified");
        $where[] = "reviewSubmitted>0";
        $q = "select *, reviewModified eventTime from PaperReview where " . join(" and ", $where) . " order by reviewModified desc, paperId asc, contactId asc limit $this->limit";

        $last = null;
        $result = $this->conf->qe_apply($q, $qv);
        while (($rrow = ReviewInfo::fetch($result, $this->conf)))
            $this->rrows[] = $last = $rrow;
        Dbl::free($result);
        $this->rrows = array_reverse($this->rrows);

        $this->rposition = false;
        if ($last)
            $this->rposition = [+$last->eventTime, +$last->paperId, +$last->contactId];
    }

    private function next_review() {
        while (1) {
            if (empty($this->rrows) && $this->rposition !== false) {
                $this->more_reviews();
                $this->load_papers();
            }
            $rrow = array_pop($this->rrows);
            if (!$rrow)
                return null;
            $prow = $this->prows->get($rrow->paperId);
            if (!$this->user->act_author_view($prow)
                && (!$this->watch || $prow->watching(WATCHTYPE_REVIEW, $this->user))
                && $this->user->can_view_review($prow, $rrow)) {
                $rrow->eventTime = (int) $rrow->eventTime;
                return $rrow;
            }
        }
    }

    private function more_comments() {
        list($where, $qv) = $this->initial_wheres($this->cposition, "timeModified");
        $q = "select *, timeModified eventTime from PaperComment where " . join(" and ", $where) . " order by timeModified desc, paperId asc, contactId asc limit $this->limit";

        $last = null;
        $result = $this->conf->qe_apply($q, $qv);
        while (($crow = CommentInfo::fetch($result, null, $this->conf)))
            $this->crows[] = $last = $crow;
        Dbl::free($result);
        $this->crows = array_reverse($this->crows);

        $this->cposition = false;
        if ($last)
            $this->cposition = [+$last->eventTime, +$last->paperId, +$last->contactId];
    }

    private function next_comment() {
        while (1) {
            if (empty($this->crows) && $this->cposition !== false) {
                $this->more_comments();
                $this->load_papers();
            }
            $crow = array_pop($this->crows);
            if (!$crow)
                return null;
            $prow = $this->prows->get($crow->paperId);
            if (!$this->user->act_author_view($prow)
                && (!$this->watch || $prow->watching(WATCHTYPE_REVIEW, $this->user))
                && $this->user->can_view_comment($prow, $crow, null)) {
                $crow->eventTime = (int) $crow->eventTime;
                return $crow;
            }
        }
    }

    private function load_papers() {
        $need = [];
        foreach ($this->rrows as $rrow)
            if (!$this->prows->get($rrow->paperId))
                $need[$rrow->paperId] = true;
        foreach ($this->crows as $crow)
            if (!$this->prows->get($crow->paperId))
                $need[$crow->paperId] = true;
        $result = $this->user->paper_result(["paperId" => array_keys($need), "watch" => true]);
        while (($prow = PaperInfo::fetch($result, $this->user)))
            $this->prows->add($prow);
    }

    static function _activity_compar($a, $b) {
        if (!$a || !$b)
            return !$a && !$b ? 0 : ($a ? -1 : 1);
        else if ($a->eventTime != $b->eventTime)
            return $a->eventTime > $b->eventTime ? -1 : 1;
        else if ($a->contactId != $b->contactId)
            return $a->contactId < $b->contactId ? -1 : 1;
        else if ($a->paperId != $b->paperId)
            return $a->paperId < $b->paperId ? -1 : 1;
        else if (isset($a->reviewType) !== isset($b->reviewType))
            return isset($a->reviewType) ? -1 : 1;
        else
            return 0;
    }

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
        while (1) {
            if (!$this->cur_rrow)
                $this->cur_rrow = $this->next_review();
            if (!$this->cur_crow)
                $this->cur_crow = $this->next_comment();
            if (!$this->cur_rrow && !$this->cur_crow)
                return $events;
            if (self::_activity_compar($this->cur_rrow, $this->cur_crow) < 0)
                $key = "cur_rrow";
            else
                $key = "cur_crow";
            $erow = $this->$key;
            if (count($events) >= $limit && $erow->eventTime < $last_time)
                return $events;
            $events[] = new PaperEvent($this->prows->get($erow->paperId), $erow);
            $last_time = $erow->eventTime;
            $this->$key = null;
        }
    }
}
