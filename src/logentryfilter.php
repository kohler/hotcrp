<?php
// logentryfilter.php -- HotCRP filter for action log entries
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class LogEntryFilter {
    /** @var Contact */
    private $user;
    /** @var array<int,true> */
    private $pidset;
    /** @var bool */
    private $want;
    private $includes;

    /** @param array<int,true> $pidset
     * @param bool $want */
    function __construct(Contact $user, $pidset, $want, $includes) {
        $this->user = $user;
        $this->pidset = $pidset;
        $this->want = $want;
        $this->includes = $includes;
    }

    private function test_pidset($row, $pidset, $want, $includes) {
        if ($row->paperId) {
            return isset($pidset[$row->paperId]) === $want
                && (!$includes || isset($includes[$row->paperId]));
        } else if (preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $row->action, $m)) {
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
                $row->action = $m[1];
                $row->paperId = $pids[0];
            } else {
                $row->action = $m[1] . " (papers " . join(", ", $pids) . ")";
            }
            return true;
        } else {
            return $this->user->privChair;
        }
    }

    /** @param LogEntry $row
     * @return bool */
    function __invoke($row) {
        if ($this->user->hidden_papers !== null
            && !$this->test_pidset($row, $this->user->hidden_papers, false, null)) {
            return false;
        } else if ($row->contactId === $this->user->contactId) {
            return true;
        } else {
            return $this->test_pidset($row, $this->pidset, $this->want, $this->includes);
        }
    }
}
