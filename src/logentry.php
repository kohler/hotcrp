<?php
// logentry.php -- HotCRP action log entries and generator
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class LogEntry {
    /** @var non-empty-string */
    public $logId;
    /** @var non-empty-string */
    public $timestamp;
    /** @var non-empty-string */
    public $contactId;
    /** @var ?non-empty-string */
    public $destContactId;
    /** @var ?non-empty-string */
    public $trueContactId;
    /** @var ?string */
    public $ipaddr;
    /** @var string */
    public $action;
    /** @var ?non-empty-string */
    public $paperId;
    public $data;

    /** @var ?string */
    public $cleanedAction;
    /** @var ?list<int> */
    public $paperIdArray;
    /** @var ?list<int> */
    public $destContactIdArray;
}

class LogEntryGenerator {
    /** @var Conf */
    private $conf;
    private $wheres;
    /** @var int */
    private $page_size;
    /** @var int */
    private $nlinks;
    /** @var int */
    private $delta = 0;
    /** @var int|float */
    private $lower_offset_bound;
    /** @var int|float */
    private $upper_offset_bound;
    /** @var int */
    private $rows_offset;
    /** @var int */
    private $rows_max_offset;
    /** @var list<LogEntry> */
    private $rows = [];
    /** @var ?LogEntryFilter */
    private $filter;
    /** @var array<int,int> */
    private $page_to_offset;
    private $log_url_base;
    /** @var bool */
    private $explode_mail = false;
    private $mail_stash;
    /** @var array<int,Contact> */
    private $users;
    /** @var array<int,true> */
    private $need_users;

    /** @param int $page_size
     * @param int $nlinks */
    function __construct(Conf $conf, $wheres, $page_size, $nlinks) {
        $this->conf = $conf;
        $this->wheres = $wheres;
        $this->page_size = $page_size;
        $this->nlinks = $nlinks;
        $this->set_filter(null);
        $this->users = $conf->pc_users();
        $this->need_users = [];
    }

    /** @param ?LogEntryFilter $filter */
    function set_filter($filter) {
        $this->filter = $filter;
        $this->rows = [];
        $this->lower_offset_bound = 0;
        $this->upper_offset_bound = INF;
        $this->page_to_offset = [];
    }

    /** @param bool $explode_mail */
    function set_explode_mail($explode_mail) {
        $this->explode_mail = $explode_mail;
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

    private function load_rows($pageno, $limit, $delta_adjusted = false) {
        $limit = (int) $limit;
        if ($pageno > 1 && $this->delta > 0 && !$delta_adjusted) {
            --$pageno;
            $limit += $this->page_size;
        }
        $offset = ($pageno - 1) * $this->page_size;
        $db_offset = $offset;
        if (($this->filter || !$this->explode_mail) && $db_offset !== 0) {
            if (!isset($this->page_to_offset[$pageno])) {
                $xlimit = min(4 * $this->page_size + $limit, 2000);
                $xpageno = max($pageno - floor($xlimit / $this->page_size), 1);
                $this->load_rows($xpageno, $xlimit, true);
                if ($this->rows_offset <= $offset && $offset + $limit <= $this->rows_max_offset)
                    return;
            }
            $xpageno = $pageno;
            while ($xpageno > 1 && !isset($this->page_to_offset[$xpageno])) {
                --$xpageno;
            }
            $db_offset = $xpageno > 1 ? $this->page_to_offset[$xpageno] : 0;
        }

        $q = "select logId, ipaddr, timestamp, contactId, destContactId, trueContactId, action, paperId from ActionLog";
        if (!empty($this->wheres)) {
            $q .= " where " . join(" and ", $this->wheres);
        }
        $q .= " order by logId desc";

        $this->rows = [];
        $this->rows_offset = $offset;
        $n = 0;
        $exhausted = false;
        while ($n < $limit && !$exhausted) {
            $result = $this->conf->qe_raw($q . " limit $db_offset,$limit");
            $first_db_offset = $db_offset;
            while (($row = $result->fetch_object("LogEntry"))) {
                '@phan-var LogEntry $row';
                $this->need_users[(int) $row->contactId] = true;
                $destuid = (int) ($row->destContactId ? : $row->contactId);
                $this->need_users[$destuid] = true;
                ++$db_offset;
                if (!$this->explode_mail
                    && $this->mail_stash
                    && $this->mail_stash->action === $row->action) {
                    $this->mail_stash->destContactIdArray[] = $destuid;
                    if ($row->paperId) {
                        $this->mail_stash->paperIdArray[] = (int) $row->paperId;
                    }
                    continue;
                }
                if (!$this->filter || call_user_func($this->filter, $row)) {
                    $this->rows[] = $row;
                    ++$n;
                    if ($n % $this->page_size === 0) {
                        $this->page_to_offset[$pageno + ($n / $this->page_size)] = $db_offset;
                    }
                    if (!$this->explode_mail) {
                        if (substr($row->action, 0, 11) === "Sent mail #") {
                            $this->mail_stash = $row;
                            $row->destContactIdArray = [$destuid];
                            $row->destContactId = null;
                            $row->paperIdArray = [];
                            if ($row->paperId) {
                                $row->paperIdArray[] = (int) $row->paperId;
                                $row->paperId = null;
                            }
                        } else {
                            $this->mail_stash = null;
                        }
                    }
                }
            }
            Dbl::free($result);
            $exhausted = $first_db_offset + $limit !== $db_offset;
        }

        if ($n > 0) {
            $this->lower_offset_bound = max($this->lower_offset_bound, $this->rows_offset + $n);
        }
        if ($exhausted) {
            $this->upper_offset_bound = min($this->upper_offset_bound, $this->rows_offset + $n);
        }
        $this->rows_max_offset = $exhausted ? INF : $this->rows_offset + $n;
    }

    /** @param int $pageno
     * @return bool */
    function has_page($pageno, $load_npages = null) {
        assert(is_int($pageno) && $pageno >= 1);
        $offset = $this->page_offset($pageno);
        if ($offset >= $this->lower_offset_bound
            && $offset < $this->upper_offset_bound) {
            if ($load_npages) {
                $limit = $load_npages * $this->page_size;
            } else {
                $limit = ($this->nlinks + 1) * $this->page_size + 30;
            }
            if ($this->filter) {
                $limit = max($limit, 2000);
            }
            $this->load_rows($pageno, $limit);
        }
        return $offset < $this->lower_offset_bound;
    }

    /** @param int $pageno
     * @param int $timestamp
     * @return bool */
    function page_after($pageno, $timestamp, $load_npages = null) {
        $rows = $this->page_rows($pageno, $load_npages);
        return !empty($rows) && $rows[count($rows) - 1]->timestamp > $timestamp;
    }

    /** @param int $pageno
     * @return list<LogEntry> */
    function page_rows($pageno, $load_npages = null) {
        assert(is_int($pageno) && $pageno >= 1);
        if (!$this->has_page($pageno, $load_npages)) {
            return [];
        }
        $offset = $this->page_offset($pageno);
        if ($offset < $this->rows_offset
            || $offset + $this->page_size > $this->rows_max_offset) {
            $this->load_rows($pageno, $this->page_size);
        }
        return array_slice($this->rows, $offset - $this->rows_offset, $this->page_size);
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
        if (!isset($row->cleanedAction)) {
            if (!isset($row->paperIdArray)) {
                $row->paperIdArray = [];
            }
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
        }
        return $row->paperIdArray;
    }

    function cleaned_action($row) {
        if (!isset($row->cleanedAction)) {
            $this->paper_ids($row);
        }
        return $row->cleanedAction;
    }
}
