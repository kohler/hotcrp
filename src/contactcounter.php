<?php
// contactcounter.php -- HotCRP user counter objects
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ContactCounter {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var bool - true if this counter is in CDB
     * @readonly */
    public $is_cdb;
    /** @var int - contactId/contactDbId
     * @readonly */
    public $contactId;
    /** @var bool - true if this counter has been loaded */
    public $is_loaded;
    /** @var int - total number of API requests made (not including _pending) */
    public $apiCount;
    /** @var int - apiCount at window 1 start */
    public $apiBase;
    /** @var int - time in msec at window 1 start */
    public $apiBaseMtime;
    /** @var int - duration in msec of window 1; null = default (1 hr) */
    public $apiRefreshWindow;
    /** @var int - refresh count of window 1; null = default (5000 req) */
    public $apiRefreshAmount;
    /** @var int - apiCount at window 2 start */
    public $apiBase2;
    /** @var int - time in msec at window 2 start */
    public $apiBaseMtime2;
    /** @var int - duration in msec of window 2; null = default (1 min) */
    public $apiRefreshWindow2;
    /** @var int - refresh count of window 2; null = default (250 req) */
    public $apiRefreshAmount2;
    /** @var int - total number of sensitive searches made */
    public $sensitiveSearchCount;
    /** @var int - total number of sensitive searches made via fallback */
    public $sensitiveSearchFallbackCount;
    /** @var int - sensitiveSearchCount at window start */
    public $sensitiveSearchBase;
    /** @var int - time in msec at window start */
    public $sensitiveSearchBaseMtime;
    /** @var int - API cost charged this request, not yet saved */
    private $_pending = 0;
    /** @var ?int - `apiBaseMtime` observed before this request refreshed window 1 */
    private $_flush_base;
    /** @var ?int - `apiBaseMtime2` observed before this request refreshed window 2 */
    private $_flush_base2;
    /** @var int - cost of the most recent `api_account` refusal */
    private $_refused_cost = 1;
    /** @var ?ContactCounter */
    private $_related;
    /** @var ?bool */
    private $_sensitive_search;

    const DEFAULT_SSRW = 60000;
    const DEFAULT_SSRA = 6;

    /** @param Conf $conf
     * @param bool $is_cdb
     * @param int $contactId */
    function __construct($conf, $is_cdb, $contactId) {
        $this->conf = $conf;
        $this->is_cdb = $is_cdb;
        $this->contactId = $contactId;
        if ($this->contactId <= 0) {
            $this->apiCount = $this->apiBase = $this->apiBase2 = 0;
            $this->apiBaseMtime = $this->apiBaseMtime2 = 0;
            $this->apiRefreshWindow = $this->apiRefreshAmount = 0;
            $this->apiRefreshWindow2 = $this->apiRefreshAmount2 = 0;
            $this->_sensitive_search = false;
            $this->is_loaded = true;
        } else {
            $this->is_loaded = false;
        }
    }

    /** @param bool $is_cdb
     * @param int $contactId
     * @return ContactCounter */
    function find($is_cdb, $contactId) {
        if ($this->is_cdb === $is_cdb) {
            assert($this->contactId === $contactId);
            return $this;
        }
        if (!$this->_related) {
            $this->_related = new ContactCounter($this->conf, $is_cdb, $contactId);
        }
        assert($this->_related->contactId === $contactId);
        return $this->_related;
    }

    /** return \mysqli */
    private function dblink() {
        return $this->is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
    }

    /** @param object $x */
    private function fetch_incorporate($x) {
        $this->apiCount = (int) $x->apiCount;
        $this->apiBase = (int) $x->apiBase;
        $this->apiBaseMtime = (int) $x->apiBaseMtime;
        if (isset($x->apiRefreshWindow)) {
            $this->apiRefreshWindow = (int) $x->apiRefreshWindow;
        } else {
            $this->apiRefreshWindow = $this->conf->opt("apiRefreshWindow") ?? 3600000;
        }
        if (isset($x->apiRefreshAmount)) {
            $this->apiRefreshAmount = (int) $x->apiRefreshAmount;
        } else {
            $this->apiRefreshAmount = $this->conf->opt("apiRefreshAmount") ?? 5000;
        }
        $this->apiBase2 = (int) $x->apiBase2;
        $this->apiBaseMtime2 = (int) $x->apiBaseMtime2;
        if (isset($x->apiRefreshWindow2)) {
            $this->apiRefreshWindow2 = (int) $x->apiRefreshWindow2;
        } else {
            $this->apiRefreshWindow2 = $this->conf->opt("apiRefreshWindow2") ?? 60000;
        }
        if (isset($x->apiRefreshAmount2)) {
            $this->apiRefreshAmount2 = (int) $x->apiRefreshAmount2;
        } else {
            $this->apiRefreshAmount2 = $this->conf->opt("apiRefreshAmount2") ?? 250;
        }
        // CDB lacks these
        if (isset($x->sensitiveSearchCount)) {
            $this->sensitiveSearchCount = (int) $x->sensitiveSearchCount;
            $this->sensitiveSearchFallbackCount = (int) $x->sensitiveSearchFallbackCount;
            $this->sensitiveSearchBase = (int) $x->sensitiveSearchBase;
            $this->sensitiveSearchBaseMtime = (int) $x->sensitiveSearchBaseMtime;
        }
    }

    /** @return $this */
    function ensure() {
        if ($this->is_loaded) {
            return $this;
        }
        $dblink = $this->dblink();
        while (true) {
            $result = Dbl::qe_raw($dblink, "select * from ContactCounter where contactId={$this->contactId}");
            $row = $result->fetch_object();
            Dbl::free($result);
            if ($row) {
                $this->fetch_incorporate($row);
                $this->is_loaded = true;
                return $this;
            }
            Dbl::qe_raw($dblink, "insert into ContactCounter set contactId={$this->contactId} on duplicate key update apiCount=apiCount");
        }
    }

    /** Requests charged to this user, including this request's unsaved charge.
     * @return int */
    function api_count() {
        return $this->apiCount + $this->_pending;
    }

    /** Account `$cost` API requests against this user's budget. Return true
     * if `$cost` is allowed. The update is charged only at shutdown.
     * @param int $cost
     * @return bool */
    function api_account($cost = 1) {
        $this->ensure();
        $nowms = (int) (Conf::$unow * 1000);
        $count = $this->api_count();
        $flush1 = $this->apiRefreshAmount > 0
            && $this->apiBaseMtime + $this->apiRefreshWindow <= $nowms;
        if (!$flush1 && $count + $cost > $this->apiBase + $this->apiRefreshAmount) {
            $this->_refused_cost = $cost;
            return false;
        }
        $flush2 = $this->apiRefreshAmount2 > 0
            && $this->apiBaseMtime2 + $this->apiRefreshWindow2 <= $nowms;
        if (!$flush2 && $count + $cost > $this->apiBase2 + $this->apiRefreshAmount2) {
            $this->_refused_cost = $cost;
            return false;
        }
        if ($flush1) {
            // `??` keeps the mtime the row actually holds, so that a second
            // refresh within one request still guards on a value the row has
            $this->_flush_base = $this->_flush_base ?? $this->apiBaseMtime;
            $this->apiBase = $count;
            $this->apiBaseMtime = $nowms;
        }
        if ($flush2) {
            $this->_flush_base2 = $this->_flush_base2 ?? $this->apiBaseMtime2;
            $this->apiBase2 = $count;
            $this->apiBaseMtime2 = $nowms;
        }
        $this->api_charge($cost);
        return true;
    }

    /** Add `$cost` to this request's API charge
     * @param int $cost */
    function api_charge($cost) {
        if ($cost <= 0 || $this->contactId <= 0) {
            return;
        }
        $this->_pending += $cost;
        $this->conf->register_shutdown_function("ContactCounterFlush")->add($this);
    }

    /** Save this request's accumulated API charge. */
    function flush_api_account() {
        if ($this->_pending <= 0) {
            return;
        }
        $cost = $this->_pending;
        $this->_pending = 0;
        // the bases are written as the values this request computed, so the
        // row and this object agree on when each window opened
        $qu = $qw = [];
        if ($this->_flush_base !== null) {
            $qu[] = "apiBase={$this->apiBase}";
            $qu[] = "apiBaseMtime={$this->apiBaseMtime}";
            $qw[] = "apiBaseMtime={$this->_flush_base}";
        }
        if ($this->_flush_base2 !== null) {
            $qu[] = "apiBase2={$this->apiBase2}";
            $qu[] = "apiBaseMtime2={$this->apiBaseMtime2}";
            $qw[] = "apiBaseMtime2={$this->_flush_base2}";
        }
        $qu[] = "apiCount=apiCount+{$cost}";
        $dblink = $this->dblink();
        $result = Dbl::qe_raw($dblink, "update ContactCounter set " . join(", ", $qu)
            . " where contactId={$this->contactId}"
            . (empty($qw) ? "" : " and " . join(" and ", $qw)));
        if ($result->affected_rows === 0 && !empty($qw)) {
            // another request opened the window first; its reset stands, but
            // this request's charge still has to land
            Dbl::qe_raw($dblink, "update ContactCounter set apiCount=apiCount+{$cost}
                where contactId={$this->contactId}");
        }
        $this->apiCount += $cost;
        $this->_flush_base = $this->_flush_base2 = null;
        $this->is_loaded = false;
    }

    function api_ratelimit_headers() {
        if ($this->apiRefreshAmount <= 0) {
            $left = 0;
        } else if ($this->apiRefreshWindow <= 0) {
            $left = PHP_INT_MAX;
        } else {
            $left = max(0, $this->apiBase + $this->apiRefreshAmount - $this->api_count());
        }
        if ($this->apiRefreshAmount2 <= 0) {
            $left2 = 0;
        } else if ($this->apiRefreshWindow2 <= 0) {
            $left2 = PHP_INT_MAX;
        } else {
            $left2 = max(0, $this->apiBase2 + $this->apiRefreshAmount2 - $this->api_count());
        }
        if ($left === PHP_INT_MAX && $left2 === PHP_INT_MAX) {
            Navigation::header("x-ratelimit-limit: unlimited");
        } else if (($left === 0 && $this->apiRefreshAmount <= 0)
                   || ($left2 === 0 && $this->apiRefreshAmount2 <= 0)) {
            Navigation::header("x-ratelimit-limit: 0");
        } else if ($left < $left2) {
            Navigation::header("x-ratelimit-limit: {$this->apiRefreshAmount}");
            Navigation::header("x-ratelimit-remaining: {$left}");
            Navigation::header("x-ratelimit-reset: " . (int) (($this->apiBaseMtime + $this->apiRefreshWindow) / 1000));
        } else {
            Navigation::header("x-ratelimit-limit: {$this->apiRefreshAmount2}");
            Navigation::header("x-ratelimit-remaining: {$left2}");
            Navigation::header("x-ratelimit-reset: " . (int) (($this->apiBaseMtime2 + $this->apiRefreshWindow2) / 1000));
        }
    }

    /** Seconds until the budget allows another request, 0 if unknown.
     * @return int */
    function api_retry_after() {
        $nowms = (int) (Conf::$unow * 1000);
        $t = 0;
        if ($this->apiRefreshAmount > 0
            && $this->apiRefreshWindow > 0
            && $this->api_count() + $this->_refused_cost > $this->apiBase + $this->apiRefreshAmount) {
            $t = max($t, $this->apiBaseMtime + $this->apiRefreshWindow - $nowms);
        }
        if ($this->apiRefreshAmount2 > 0
            && $this->apiRefreshWindow2 > 0
            && $this->api_count() + $this->_refused_cost > $this->apiBase2 + $this->apiRefreshAmount2) {
            $t = max($t, $this->apiBaseMtime2 + $this->apiRefreshWindow2 - $nowms);
        }
        return (int) ceil($t / 1000);
    }

    /** @return JsonResult */
    function api_fail() {
        if ($this->apiRefreshAmount <= 0 || $this->apiRefreshAmount2 <= 0) {
            return JsonResult::make_error(403, "<0>API access disabled");
        }
        $jr = JsonResult::make_error(429, "<0>Rate limit exceeded");
        if (($delay = $this->api_retry_after()) > 0) {
            $jr->set_header("Retry-After: {$delay}");  // RFC 9110 §10.2.3
        }
        return $jr;
    }

    /** Account one sensitive (timing-channel-prone) search against this user's
     * budget. Returns false if the user is over budget, in which case the
     * caller should fall back to leak-free `precise_sqlexpr()`. All sensitive
     * searches during the same request are treated the same.
     * Do not require a loaded counter.
     * @return bool */
    function sensitive_search_account() {
        if ($this->_sensitive_search !== null) {
            return $this->_sensitive_search;
        }
        $defwindow = (int) ($this->conf->opt("sensitiveSearchRefreshWindow") ?? self::DEFAULT_SSRW);
        $defamount = (int) ($this->conf->opt("sensitiveSearchRefreshAmount") ?? self::DEFAULT_SSRA);
        if ($defamount <= 0) {
            $this->_sensitive_search = false;
        } else if ($defwindow <= 0) {
            $this->_sensitive_search = true;
        } else {
            $nowms = (int) (Conf::$unow * 1000);
            $neednewbase = "sensitiveSearchBaseMtime+{$defwindow}<={$nowms}";
            $result = Dbl::qe($this->dblink(), "update ContactCounter
                set sensitiveSearchBase=if({$neednewbase},sensitiveSearchCount,sensitiveSearchBase),
                    sensitiveSearchBaseMtime=if({$neednewbase},{$nowms},sensitiveSearchBaseMtime),
                    sensitiveSearchCount=sensitiveSearchCount+1
                where contactId={$this->contactId}
                    and ({$neednewbase} or sensitiveSearchCount<sensitiveSearchBase+{$defamount})");
            $this->_sensitive_search = $result->affected_rows > 0;
            if (!$this->_sensitive_search) {
                $result = Dbl::qe($this->dblink(), "insert into ContactCounter
                    set contactId={$this->contactId},
                        sensitiveSearchBaseMtime={$nowms},
                        sensitiveSearchCount=1
                    on duplicate key update sensitiveSearchFallbackCount=sensitiveSearchFallbackCount+1");
                $this->_sensitive_search = $result->affected_rows === 1;
            }
            $this->is_loaded = false;
        }
        return $this->_sensitive_search;
    }
}

class ContactCounterFlush {
    /** @var list<ContactCounter> */
    private $_counters = [];

    function __construct(Conf $conf) {
    }

    /** @param ContactCounter $ctr */
    function add($ctr) {
        if (!in_array($ctr, $this->_counters, true)) {
            $this->_counters[] = $ctr;
        }
    }

    function __invoke() {
        $ctrs = $this->_counters;
        $this->_counters = [];
        foreach ($ctrs as $ctr) {
            $ctr->flush_api_account();
        }
    }
}
