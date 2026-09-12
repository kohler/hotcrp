<?php
// logentryfilter.php -- HotCRP filter for action log entries
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class LogEntryFilter {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $viewer;
    /** @var array<int,true> */
    private $pidset;
    /** @var bool */
    private $want;
    /** @var ?array<int,mixed> */
    private $includes;
    /** @var bool */
    private $need_tags = false;

    /** @param array<int,true> $pidset
     * @param bool $want */
    function __construct(Contact $viewer, $pidset, $want, $includes) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->pidset = $pidset;
        $this->want = $want;
        $this->includes = $includes;
    }

    /** @return LogEntryFilter */
    static function make_nonchair(Contact $viewer, PaperInfoSet $rowset, $includes) {
        $filter = new LogEntryFilter($viewer, array_fill_keys($rowset->paper_ids(), true), true, $includes);
        $filter->need_tags = true;
        return $filter;
    }

    /** @param LogEntry $le */
    private function test_pidset($le, $pidset, $want, $includes) {
        if ($le->paperId) {
            return isset($pidset[$le->paperId]) === $want
                && (!$includes || isset($includes[$le->paperId]));
        }
        if (!preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $le->action, $m)) {
            return $this->viewer->privChair;
        }
        preg_match_all('/\d+/', $m[2], $mm);
        $pids = [];
        $included = !$includes;
        foreach ($mm[0] as $pid) {
            if (isset($pidset[$pid]) === $want) {
                $pids[] = $pid;
                $included = $included || isset($includes[$pid]);
            }
        }
        if (empty($pids) || !$included) {
            return false;
        } else if (count($pids) === 1) {
            $le->action = $m[1];
            $le->paperId = (int) $pids[0];
        } else {
            $le->action = $m[1] . " (papers " . join(", ", $pids) . ")";
        }
        return true;
    }

    private function test_tags($le) {
        // NB This filter assumes that $this->viewer is the paper manager
        // for a paper touched by this log entry, so censoring TF_CHAIR_HIDDEN
        // tags will suffice. If we ever show log entries to non-managers
        // (not recommended), this would need updating.
        if (!preg_match('/\A(Tag:?+)((?: [-+]\#[^\s\#]*+(?:\#[-+\d.]++|))++)(.*)\z/s', $le->action, $m)) {
            return true;
        }
        $ca = $m[1];
        $dt = $this->conf->tags();
        foreach (explode(" ", $m[2]) as $ta) {
            if ($ta === "") {
                continue;
            }
            $hash = strpos($ta, "#", 2);
            if ($hash === false) {
                $hash = strlen($ta);
            }
            $tag = substr($ta, 2, $hash - 2);
            if (!$dt->find_having($tag, TagInfo::TF_CHAIR_HIDDEN)) {
                $ca .= " " . $ta;
            }
        }
        if ($ca === $m[1]) {
            return false;
        }
        $le->action = $ca . $m[3];
        return true;
    }

    /** @param LogEntry $le
     * @return bool */
    function __invoke($le) {
        if ($this->viewer->hidden_papers !== null
            && !$this->test_pidset($le, $this->viewer->hidden_papers, false, null)) {
            return false;
        } else if ($le->contactId === $this->viewer->contactId) {
            return true;
        }
        return $this->test_pidset($le, $this->pidset, $this->want, $this->includes)
            && (!$this->need_tags
                || !str_starts_with($le->action, "Tag")
                || $this->test_tags($le));
    }
}
