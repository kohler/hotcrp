<?php
// logentry.php -- HotCRP action log entries and generator
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class LogEntry {
    /** @var int */
    public $logId;
    /** @var int */
    public $timestamp;
    /** @var int */
    public $contactId;
    /** @var ?int */
    public $destContactId;
    /** @var ?int */
    public $trueContactId;
    /** @var ?string */
    public $ipaddr;
    /** @var string */
    public $action;
    /** @var ?int */
    public $paperId;
    public $data;

    /** @var ?string */
    public $cleanedAction;
    /** @var ?list<int> */
    public $paperIdArray;
    /** @var ?list<int> */
    public $destContactIdArray;
    /** @var int */
    public $ordinal;

    function incorporate() {
        $this->logId = (int) $this->logId;
        $this->timestamp = (int) $this->timestamp;
        $this->contactId = (int) $this->contactId;
        $this->destContactId = (int) $this->destContactId;
        $this->trueContactId = (int) $this->trueContactId;
        if ($this->paperId !== null) {
            $this->paperId = (int) $this->paperId;
        }
    }

    /** @return string */
    function unparse_via() {
        $tcid = $this->trueContactId ?? 0;
        if ($tcid === 0) {
            return "";
        } else if ($tcid > 0) {
            return "admin";
        } else if ($tcid === -1) {
            return "link";
        } else if ($tcid === -2) {
            return "API token";
        } else if ($tcid === -3) {
            return "command line";
        } else {
            return "unknown";
        }
    }
}

class LogEntryGenerator {
    /** @var Conf */
    private $conf;
    /** @var ?list<int> */
    private $pids;
    /** @var ?list<int> */
    private $uids;
    /** @var ?string */
    private $email_regex;
    /** @var null|false|string */
    private $action_regex;
    /** @var int */
    private $page_size;
    /** @var int */
    private $delta = 0;
    /** @var list<LogEntry> */
    private $rows = [];
    /** @var ?LogEntryFilter */
    private $filter;
    /** @var list<LogEntry> */
    private $signpost_rows;
    /** @var int */
    private $nrows;
    /** @var int */
    private $first_index;
    /** @var int */
    private $last_known_index;
    private $log_url_base;
    /** @var bool */
    private $consolidate_mail = true;
    /** @var ?LogEntry */
    private $consolidate_row;
    /** @var array<int,Contact> */
    private $users;
    /** @var array<int,true> */
    private $need_users;

    private static $csv_role_map = [
        "", "pc", "sysadmin", "pc sysadmin", "chair", "chair", "chair", "chair"
    ];

    /** @param int $page_size */
    function __construct(Conf $conf, $page_size) {
        assert(Contact::ROLE_PC === 1 && Contact::ROLE_ADMIN === 2 && Contact::ROLE_CHAIR === 4);
        $this->conf = $conf;
        $this->page_size = $page_size;
        $this->set_filter(null);
        $this->users = $conf->pc_users();
        $this->need_users = [];
    }

    private function reset() {
        $this->rows = [];
        $this->first_index = $this->last_known_index = 0;
        $this->nrows = PHP_INT_MAX;
        $this->signpost_rows = [];
        $this->consolidate_row = null;
    }

    /** @param ?LogEntryFilter $filter
     * @return $this */
    function set_filter($filter) {
        $this->filter = $filter;
        $this->reset();
        return $this;
    }

    /** @param bool $consolidate_mail
     * @return $this */
    function set_consolidate_mail($consolidate_mail) {
        if ($consolidate_mail !== $this->consolidate_mail) {
            $this->consolidate_mail = $consolidate_mail;
            $this->reset();
        }
        return $this;
    }

    /** @param ?list<int> $pids
     * @return $this */
    function set_paper_ids($pids) {
        $this->pids = $pids;
        $this->reset();
        return $this;
    }

    /** @param ?list<int> $uids
     * @return $this */
    function set_user_ids($uids) {
        $this->uids = $uids;
        if ($uids !== null) {
            $ex = [];
            foreach (Dbl::fetch_first_columns($this->conf->dblink, "select email from ContactInfo where contactId?a union select email from DeletedContactInfo where contactId?a", $uids, $uids) as $email) {
                $ex[] = addcslashes($email, '^$.*+?|(){}[]\\');
            }
            $this->email_regex = ' (' . join("|", $ex) . ')';
        }
        $this->reset();
        return $this;
    }

    /** @param ?list<string> $matchers
     * @return $this */
    function set_action_matchers($matchers) {
        if ($matchers === null) {
            $this->action_regex = null;
        } else if ($matchers === []) {
            $this->action_regex = false;
        } else {
            $ex = [];
            foreach ($matchers as $m) {
                $t = addcslashes($m, '^$.*+?|(){}[]\\');
                $ex[] = str_replace('\\*', ".*", $t);
            }
            $this->action_regex = '(' . join("|", $ex) . ')';
        }
        $this->reset();
        return $this;
    }

    /** @return bool */
    function has_filter() {
        return !!$this->filter;
    }

    /** @return int */
    function page_size() {
        return $this->page_size;
    }

    /** @return int */
    function page_delta() {
        return $this->delta;
    }

    /** @param int $delta */
    function set_page_delta($delta) {
        assert(is_int($delta) && $delta >= 0 && $delta < $this->page_size);
        $this->delta = $delta;
    }

    /** @param int $pageno
     * @return int */
    private function page_offset($pageno) {
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset > 0 && $this->delta > 0) {
            $offset -= $this->page_size - $this->delta;
        }
        return $offset;
    }

    /** @param int $pageno
     * @return int */
    function page_index($pageno) {
        $index = ($pageno - 1) * $this->page_size;
        if ($index > 0 && $this->delta > 0) {
            $index -= $this->page_size - $this->delta;
        }
        return $index;
    }

    /** @return string */
    private function unparse_pids_where(&$qv) {
        if (empty($this->pids)) {
            return "false";
        }
        $qv[] = $this->pids;
        $qv[] = "\\(papers.* (" . join("|", $this->pids) . ")[,)]";
        return "(paperId?a or action rlike " . Dbl::utf8ci($this->conf->dblink, "?") . ")";
    }

    /** @return string */
    private function unparse_uids_where(&$qv) {
        if (empty($this->uids)) {
            return "false";
        }
        $qv[] = $this->uids;
        $qv[] = $this->uids;
        $qv[] = $this->email_regex;
        // XXX trueContactId (actas)?
        return "(contactId?a or destContactId?a or action rlike " . Dbl::utf8ci($this->conf->dblink, "?") . ")";
    }

    /** @return string */
    private function unparse_action_where(&$qv) {
        if ($this->action_regex === false) {
            return "false";
        }
        $qv[] = $this->action_regex;
        return "action rlike " . Dbl::utf8ci($this->conf->dblink, "?");
    }

    /** @return string */
    private function unparse_query_base() {
        $wheres = $qv = [];
        if ($this->pids !== null) {
            $wheres[] = $this->unparse_pids_where($qv);
        }
        if ($this->uids !== null) {
            $wheres[] = $this->unparse_uids_where($qv);
        }
        if ($this->action_regex !== null) {
            $wheres[] = $this->unparse_action_where($qv);
        }
        if (empty($wheres)) {
            $wheres[] = "true";
        }
        return Dbl::format_query_apply($this->conf->dblink,
            "select logId, ipaddr, timestamp, contactId, destContactId, trueContactId, action, paperId
            from ActionLog where " . join(" and ", $wheres),
            $qv);
    }

    /** @param LogEntry $row */
    private function add_signpost_row($row) {
        $n = count($this->signpost_rows);
        while ($n > 0 && $row->ordinal < $this->signpost_rows[$n - 1]->ordinal) {
            --$n;
        }
        if ($n > 0 && $row->ordinal === $this->signpost_rows[$n - 1]->ordinal) {
            $this->signpost_rows[$n - 1] = $row;
        } else {
            array_splice($this->signpost_rows, $n, 0, [$row]);
        }
    }

    /** @param int $first
     * @param int $last
     * @param bool $expand */
    function load_row_range($first, $last, $expand = false) {
        // constrain [$first, $last) to existing rows, exit if satisfied
        $last = min($last, $this->nrows);
        $first = min($first, $last);
        // the last row isn't valid if still being consolidated
        $valid_last = $this->first_index + count($this->rows)
            - ($this->consolidate_row ? 1 : 0);
        if ($first === $last
            || ($first >= $this->first_index && $last <= $valid_last)) {
            return;
        }
        if ($expand && $last - $first < 2000) {
            $last = min($first + 2000, $this->nrows);
        }

        // remove unneeded rows
        if ($first < $this->first_index
            || $first >= $this->first_index + count($this->rows)) {
            $this->rows = [];
            $this->first_index = $first;
            $this->consolidate_row = null;
        } else if ($first >= $this->first_index + 10000) {
            $this->rows = array_slice($this->rows, $first - $this->first_index);
            $this->first_index = $first;
        }

        // if loading from scratch, find reasonable boundary
        if (!empty($this->rows)) {
            assert($first <= $this->first_index + count($this->rows));
            $br = $this->rows[count($this->rows) - 1];
            $ordinal = $br->ordinal + 1;
            $limit_logid = $br->logId;
        } else {
            $bi = 0;
            while ($bi !== count($this->signpost_rows)
                   && $this->signpost_rows[$bi]->ordinal < $first) {
                ++$bi;
            }
            $br = $bi > 0 ? $this->signpost_rows[$bi - 1] : null;
            if ((!$br || $first - $br->ordinal > 2000)
                && !$this->filter
                && !$this->consolidate_mail) {
                $ordinal = $first;
                $limit_logid = null;
            } else if ($br) {
                $ordinal = $br->ordinal + 1;
                $limit_logid = $br->logId;
            } else {
                $ordinal = 0;
                $limit_logid = 0;
            }
        }

        // loop until satisfied
        $qbase = $this->unparse_query_base();
        while ($last > $this->first_index + count($this->rows)
               || ($last === $this->first_index + count($this->rows)
                   && $this->consolidate_row)) {
            // construct query
            $q = $qbase;
            if ($limit_logid !== null && $ordinal !== 0) {
                $q .= " and logId<{$limit_logid}";
            }
            $q .= " order by logId desc";
            $xlimit = $last - ($this->first_index + count($this->rows));
            if ($this->filter || $this->consolidate_mail) {
                $xlimit += 200;
            }
            if ($limit_logid !== null || $ordinal === 0) {
                $q .= " limit {$xlimit}";
            } else {
                $q .= " limit {$ordinal},{$xlimit}";
            }

            // fetch results
            $result = $this->conf->qe_raw($q);
            $n = 0;
            while (($row = $result->fetch_object("LogEntry"))) {
                '@phan-var LogEntry $row';
                $row->incorporate();
                $limit_logid = $row->logId;
                ++$n;

                $destuid = $row->destContactId ? : $row->contactId;
                $this->need_users[$row->contactId] = true;
                $this->need_users[$destuid] = true;

                // consolidate mail rows
                if ($this->consolidate_row
                    && $this->consolidate_row->action === $row->action) {
                    $this->consolidate_row->destContactIdArray[] = $destuid;
                    if ($row->paperId) {
                        $this->consolidate_row->paperIdArray[] = $row->paperId;
                    }
                    $this->consolidate_row->logId = $row->logId;
                    if ($ordinal % 1000 === 0) {
                        $row->ordinal = $ordinal - 1;
                        $this->add_signpost_row($row);
                    }
                    continue;
                }

                // skip filtered rows
                if ($this->filter && !call_user_func($this->filter, $row)) {
                    continue;
                }

                // incorporate row
                $row->ordinal = $ordinal;
                if ($ordinal >= $first) {
                    $this->rows[] = $row;
                }
                ++$ordinal;
                if ($ordinal % 1000 === 0) {
                    $this->add_signpost_row($row);
                }

                // maybe mark row for consolidation
                if (!$this->consolidate_mail) {
                    continue;
                }
                if (str_starts_with($row->action, "Sent mail #")) {
                    $this->consolidate_row = $row;
                    $row->destContactIdArray = [$destuid];
                    $row->destContactId = null;
                    $row->paperIdArray = [];
                    if ($row->paperId) {
                        $row->paperIdArray[] = $row->paperId;
                        $row->paperId = null;
                    }
                } else {
                    $this->consolidate_row = null;
                }
            }
            $result->close();

            // maybe mark end
            $this->last_known_index = max($this->last_known_index, $ordinal);
            if ($n < $xlimit) {
                $this->nrows = $ordinal;
                $last = min($last, $ordinal);
                $this->consolidate_row = null;
            }
        }
    }

    /** @param int $pageno
     * @return bool */
    function has_page($pageno) {
        assert(is_int($pageno) && $pageno >= 1);
        $idx = $this->page_index($pageno);
        if ($idx >= $this->nrows) {
            return false;
        }
        if ($idx >= $this->last_known_index) {
            $this->load_row_range($idx, $idx + 2000);
        }
        return $idx < $this->last_known_index;
    }

    /** @param int $pageno
     * @param bool $expand
     * @return list<LogEntry> */
    function page_rows($pageno, $expand = false) {
        assert(is_int($pageno) && $pageno >= 1);
        $first = $this->page_index($pageno);
        $this->load_row_range($first, $first + $this->page_size, $expand);
        $i0 = max($first, $this->first_index);
        $i1 = min($first + $this->page_size, $this->first_index + count($this->rows));
        if ($i0 >= $i1) {
            return [];
        }
        return array_slice($this->rows, $i0 - $this->first_index, $i1 - $i0);
    }

    /** @param string $url */
    function set_log_url_base($url) {
        $this->log_url_base = $url;
    }

    /** @param int|'earliest' $pageno
     * @param int|string $html
     * @return string */
    function page_link_html($pageno, $html) {
        $url = $this->log_url_base;
        if ($pageno !== 1 && $this->delta > 0) {
            $url .= "&amp;offset=" . $this->delta;
        }
        return "<a href=\"{$url}&amp;page={$pageno}\">{$html}</a>";
    }

    private function _make_users() {
        unset($this->need_users[0]);
        $this->need_users = array_diff_key($this->need_users, $this->users);
        if (!empty($this->need_users)) {
            $result = $this->conf->qe("select " . $this->conf->user_query_fields() . " from ContactInfo where contactId?a", array_keys($this->need_users));
            while (($user = Contact::fetch($result, $this->conf))) {
                $this->users[$user->contactId] = $user;
                unset($this->need_users[$user->contactId]);
            }
            Dbl::free($result);
        }
        if (!empty($this->need_users)) {
            foreach ($this->need_users as $cid => $x) {
                $this->users[$cid] = Contact::make_deleted($this->conf, $cid);
            }
            $result = $this->conf->qe("select " . $this->conf->deleted_user_query_fields() . " from DeletedContactInfo where contactId?a", array_keys($this->need_users));
            while (($user = Contact::fetch($result, $this->conf))) {
                $this->users[$user->contactId] = $user;
            }
            Dbl::free($result);
        }
        $this->need_users = [];
    }

    /** @param LogEntry $row
     * @param 'contactId'|'destContactId'|'trueContactId' $key
     * @return list<Contact> */
    function users_for($row, $key) {
        if (!empty($this->need_users)) {
            $this->_make_users();
        }
        $uid = (int) $row->$key;
        if (!$uid && $key === "contactId") {
            $uid = (int) $row->destContactId;
        }
        $u = $uid ? [$this->users[$uid]] : [];
        if ($key === "destContactId" && isset($row->destContactIdArray)) {
            foreach ($row->destContactIdArray as $uid) {
                $u[] = $this->users[$uid];
            }
        }
        return $u;
    }

    /** @param LogEntry $row
     * @return list<int> */
    function paper_ids($row) {
        if (isset($row->cleanedAction)) {
            return $row->paperIdArray;
        }
        $row->paperIdArray = $row->paperIdArray ?? [];
        if (preg_match('/\A(.* |)\(papers ([\d, ]+)\)?\z/', $row->action, $m)) {
            $row->cleanedAction = rtrim($m[1]);
            foreach (preg_split('/[\s,]+/', $m[2]) as $p) {
                if ($p !== "")
                    $row->paperIdArray[] = (int) $p;
            }
        } else {
            $row->cleanedAction = $row->action;
        }
        if ($row->paperId) {
            $row->paperIdArray[] = (int) $row->paperId;
        }
        $row->paperIdArray = array_values(array_unique($row->paperIdArray));
        return $row->paperIdArray;
    }

    /** @param LogEntry $row */
    function cleaned_action($row) {
        if (!isset($row->cleanedAction)) {
            $this->paper_ids($row);
        }
        return $row->cleanedAction;
    }

    /** @return list<string> */
    function narrow_csv_fields() {
        return [
            "date", "ipaddr", "email", "roles", "affected_email", "via",
            "paper", "action"
        ];
    }

    /** @return list<string> */
    function wide_csv_fields() {
        return [
            "date", "ipaddr", "email", "affected_email", "via",
            "papers", "action"
        ];
    }

    /** @param LogEntry $row
     * @return list<list<string>> */
    function narrow_csv_data_list($row) {
        $date = date("Y-m-d H:i:s O", $row->timestamp);
        $xusers = $this->users_for($row, "contactId");
        $xdest_users = $this->users_for($row, "destContactId");
        $via = $row->unparse_via();
        $pids = $this->paper_ids($row);
        $action = $this->cleaned_action($row);

        // ensure one of each
        if (empty($xusers)) {
            $xusers = [null];
        }
        if ($xdest_users === $xusers || empty($xdest_users)) {
            $xdest_users = [null];
        }
        if (empty($pids)) {
            $pids = [""];
        }

        // one row per (user, dest_user, pid)
        $rows = [];
        foreach ($xusers as $u1) {
            $u1e = $u1 ? $u1->email : "";
            $u1r = $u1 ? self::$csv_role_map[$u1->roles & 7] : "";
            foreach ($xdest_users as $u2) {
                $u2e = $u2 ? $u2->email : "";
                foreach ($pids as $p) {
                    $rows[] = [$date, $row->ipaddr ?? "", $u1e, $u1r, $u2e, $via, $p, $action];
                }
            }
        }
        return $rows;
    }

    /** @param LogEntry $row
     * @return list<string> */
    function wide_csv_data($row) {
        $date = date("Y-m-d H:i:s O", $row->timestamp);
        $xusers = $this->users_for($row, "contactId");
        $xdest_users = $this->users_for($row, "destContactId");
        $via = $row->unparse_via();
        $pids = $this->paper_ids($row);
        $action = $this->cleaned_action($row);

        $u1es = $u2es = [];
        foreach ($xusers as $u) {
            $u1es[] = $u->email;
        }
        if ($xdest_users !== $xusers) {
            foreach ($xdest_users as $u) {
                $u2es[] = $u->email;
            }
        }

        return [$date, $row->ipaddr ?? "", join(" ", $u1es), join(" ", $u2es), $via, join(" ", $pids), $action];
    }
}
