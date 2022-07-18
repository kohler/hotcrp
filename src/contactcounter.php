<?php
// contactcounter.php -- HotCRP user counter objects
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ContactCounter {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $is_cdb;
    /** @var int */
    public $contactId;
    /** @var int */
    public $apiCount;
    /** @var int */
    public $apiLimit;
    /** @var int */
    public $apiRefreshMtime;
    /** @var int */
    public $apiRefreshWindow;
    /** @var int */
    public $apiRefreshAmount;
    /** @var int */
    public $apiLimit2;
    /** @var int */
    public $apiRefreshMtime2;
    /** @var int */
    public $apiRefreshWindow2;
    /** @var int */
    public $apiRefreshAmount2;
    /** @var int */
    private $_api_refresh_bits;

    /** @param Conf $conf
     * @param bool $is_cdb */
    function __construct($conf, $is_cdb) {
        $this->conf = $conf;
        $this->is_cdb = $is_cdb;
    }

    /** return \mysqli */
    private function dblink() {
        return $this->is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
    }

    /** @param ContactCounter $x */
    private function fetch_incorporate($x) {
        $this->contactId = (int) $x->contactId;
        $this->apiCount = (int) $x->apiCount;
        $this->apiLimit = (int) $x->apiLimit;
        $this->apiRefreshMtime = (int) $x->apiRefreshMtime;
        $this->apiRefreshWindow = (int) $x->apiRefreshWindow;
        $this->apiRefreshAmount = (int) $x->apiRefreshAmount;
        $this->apiLimit2 = (int) $x->apiLimit2;
        $this->apiRefreshMtime2 = (int) $x->apiRefreshMtime2;
        $this->apiRefreshWindow2 = (int) $x->apiRefreshWindow2;
        $this->apiRefreshAmount2 = (int) $x->apiRefreshAmount2;
        $this->_api_refresh_bits = 0;
    }

    /** @return bool */
    private function reload() {
        $result = Dbl::qe_raw($this->dblink(), "select * from ContactCounter where contactId={$this->contactId}");
        $row = $result->fetch_object("ContactCounter", [$this->conf, $this->is_cdb]);
        Dbl::free($result);
        if ($row) {
            $this->fetch_incorporate($row);
            return true;
        } else {
            return false;
        }
    }

    /** @param Conf $conf
     * @param bool $is_cdb
     * @param int $contactId
     * @return ContactCounter */
    static function find_by_uid($conf, $is_cdb, $contactId) {
        $dblink = $is_cdb ? $conf->contactdb() : $conf->dblink;
        while (true) {
            $result = Dbl::qe($dblink, "select * from ContactCounter where contactId=?", $contactId);
            $row = $result->fetch_object("ContactCounter", [$conf, $is_cdb]);
            Dbl::free($result);
            if ($row !== null) {
                $row->fetch_incorporate($row);
                return $row;
            }
            Dbl::qe($dblink, "insert into ContactCounter set contactId=? on duplicate key update apiCount=apiCount", $contactId);
        }
    }

    private function api_incorporate_options() {
        if ($this->_api_refresh_bits === 0) {
            $this->_api_refresh_bits = 1;
            if ($this->apiRefreshWindow === 0) {
                $this->apiRefreshWindow = $this->conf->opt("apiRefreshWindow") ?? 3600000;
                $this->_api_refresh_bits |= 2;
            }
            if ($this->apiRefreshAmount === 0) {
                $this->apiRefreshAmount = $this->conf->opt("apiRefreshAmount") ?? 1000;
                $this->_api_refresh_bits |= 4;
            }
            if ($this->apiRefreshWindow < 0 && $this->apiRefreshAmount < 0) {
                $this->apiLimit = -1;
            } else if ($this->apiRefreshWindow === 0 || $this->apiRefreshAmount === 0) {
                $this->apiLimit = 0;
            }
            if ($this->apiRefreshWindow2 === 0) {
                $this->apiRefreshWindow2 = $this->conf->opt("apiRefreshWindow2") ?? 60000;
                $this->_api_refresh_bits |= 8;
            }
            if ($this->apiRefreshAmount2 === 0) {
                $this->apiRefreshAmount2 = $this->conf->opt("apiRefreshAmount2") ?? 100;
                $this->_api_refresh_bits |= 16;
            }
            if ($this->apiRefreshWindow2 < 0 && $this->apiRefreshAmount2 < 0) {
                $this->apiLimit2 = -1;
            } else if ($this->apiRefreshWindow2 === 0 || $this->apiRefreshAmount2 === 0) {
                $this->apiLimit2 = 0;
            }
        }
    }

    function api_refresh() {
        $nowms = null;
        while (true) {
            $refresh = $refresh2 = false;
            $this->api_incorporate_options();

            if ($this->apiRefreshWindow > 0 && $this->apiRefreshAmount > 0) {
                if ($this->apiRefreshMtime === 0) {
                    $refresh = true;
                } else if ($this->apiRefreshMtime < Conf::$now * 1000 + 1000) {
                    $nowms = $nowms ?? (int) (microtime(true) * 1000);
                    $refresh = $this->apiRefreshMtime <= $nowms;
                }
            }

            if ($this->apiRefreshWindow2 > 0 && $this->apiRefreshAmount2 > 0) {
                if ($this->apiRefreshMtime2 === 0) {
                    $refresh2 = true;
                } else if ($this->apiRefreshMtime2 < Conf::$now * 1000 + 1000) {
                    $nowms = $nowms ?? (int) (microtime(true) * 1000);
                    $refresh2 = $this->apiRefreshMtime2 <= $nowms;
                }
            }

            if (!$refresh && !$refresh2) {
                return;
            }

            $qu = [];
            $qw = ["contactId={$this->contactId}", "apiCount={$this->apiCount}"];
            $nowms = $nowms ?? (int) (microtime(true) * 1000);
            if ($refresh) {
                $qu[] = "apiLimit=" . ($this->apiCount + $this->apiRefreshAmount);
                $qu[] = "apiRefreshMtime=" . ($nowms + $this->apiRefreshWindow);
                $qw[] = "apiRefreshMtime=" . $this->apiRefreshMtime;
            }
            if ($refresh2) {
                $qu[] = "apiLimit2=" . ($this->apiCount + $this->apiRefreshAmount2);
                $qu[] = "apiRefreshMtime2=" . ($nowms + $this->apiRefreshWindow2);
                $qw[] = "apiRefreshMtime2=" . $this->apiRefreshMtime2;
            }

            Dbl::qe_raw($this->dblink(), "update ContactCounter set " . join(", ", $qu) . " where " . join(" and ", $qw));

            if (!$this->reload()) {
                return;
            }
        }
    }

    /** @param bool $complete_request
     * @return bool */
    function api_account($complete_request) {
        $nowms = null;
        while (true) {
            $this->api_incorporate_options();
            if ($this->apiLimit === 0
                || ($this->apiLimit > 0 && $this->apiCount >= $this->apiLimit)
                || $this->apiLimit2 === 0
                || ($this->apiLimit2 > 0 && $this->apiCount >= $this->apiLimit2)) {
                return $this->api_account_fail($complete_request);
            }

            $result = Dbl::qe_raw($this->dblink(), "update ContactCounter set apiCount=apiCount+1 where contactId={$this->contactId} and apiCount={$this->apiCount}");
            if ($result->affected_rows > 0) {
                ++$this->apiCount;
                if ($complete_request) {
                    $this->api_ratelimit_headers();
                }
                return true;
            }

            if (!$this->reload()) {
                return $this->api_account_fail($complete_request);
            }
        }
    }

    /** @param bool $complete_request
     * @return false */
    private function api_account_fail($complete_request) {
        if ($complete_request) {
            $this->api_ratelimit_headers();
            if ($this->apiLimit === 0 || $this->apiLimit2 === 0) {
                JsonResult::make_error(403, "<0>API access disabled")->complete();
            } else {
                JsonResult::make_error(429, "<0>Rate limit exceeded")->complete();
            }
        } else {
            return false;
        }
    }

    function api_ratelimit_headers() {
        if ($this->apiLimit === 0 || $this->apiLimit2 === 0) {
            header("x-ratelimit-limit: 0");
        } else if ($this->apiLimit < 0 && $this->apiLimit2 < 0) {
            header("x-ratelimit-limit: unlimited");
        } else {
            $left = $this->apiLimit > 0 ? max(0, $this->apiLimit - $this->apiCount) : PHP_INT_MAX;
            $left2 = $this->apiLimit2 > 0 ? max(0, $this->apiLimit2 - $this->apiCount) : PHP_INT_MAX;
            if ($left === 0
                ? $left2 > 0 || $this->apiRefreshMtime > $this->apiRefreshMtime2
                : $left2 > 0 && $this->apiRefreshWindow >= $this->apiRefreshWindow2) {
                header("x-ratelimit-limit: {$this->apiRefreshAmount}");
                header("x-ratelimit-remaining: " . max($this->apiLimit - $this->apiCount, 0));
                header("x-ratelimit-reset: " . (int) ($this->apiRefreshMtime / 1000));
            } else {
                header("x-ratelimit-limit: {$this->apiRefreshAmount2}");
                header("x-ratelimit-remaining: " . max($this->apiLimit2 - $this->apiCount, 0));
                header("x-ratelimit-reset: " . (int) ($this->apiRefreshMtime2 / 1000));
            }
        }
    }
}
