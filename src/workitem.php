<?php
// workitem.php -- HotCRP class representing work
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WorkItem {
    /** @var \mysqli */
    private $dblink;
    /** @var ?int  local if null, CDB if nonnull */
    public $serverId;
    /** @var ?string  only set if CDB */
    public $root;
    /** @var string  primary dispatch */
    public $workType;
    /** @var string */
    public $workSubtype;
    /** @var string */
    public $work;
    /** @var int */
    public $touchedAt = 0;
    /** @var int */
    public $dequeuedAt = 0;
    /** @var int */
    private $workpos = 0;
    /** @var int */
    private $workendpos = 0;


    /** @param string $workType
     * @param ?string $workSubtype
     * @param object|array|string $work
     * @return ?WorkItem */
    static function make(Conf $conf, $workType, $workSubtype, $work) {
        if (is_string($work)) {
            assert($work !== "" && str_starts_with($work, "\x1E") && str_ends_with($work, "\n"));
            $workstr = $work;
        } else {
            $workstr = json_encode_db($work);
            assert($workstr !== false);
            if ($workstr === false) {
                return null;
            }
            $workstr = "\x1E" . $workstr .  "\n";
        }
        $wi = new WorkItem;
        if ($conf->opt("processWorkContactdb")
            && ($cdb = $conf->contactdb())
            && ($serverId = SiteLoader::server_id())) {
            $wi->dblink = $cdb;
            $wi->serverId = $serverId;
            $wi->root = SiteLoader::$root;
        } else {
            $wi->dblink = $conf->dblink;
        }
        $wi->workType = $workType;
        $wi->workSubtype = $workSubtype ?? "";
        $wi->work = $workstr;
        $wi->touchedAt = $wi->dequeuedAt = Conf::$now;
        return $wi;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function incorporate($dblink) {
        $this->dblink = $dblink;
        if (isset($this->serverId)) {
            $this->serverId = (int) $this->serverId;
        }
        $this->touchedAt = (int) $this->touchedAt;
        $this->dequeuedAt = (int) $this->dequeuedAt;
    }

    /** @param mysqli_result|Dbl_Result $result
     * @oaram \mysql $dblink
     * @return ?WorkItem */
    static function fetch($result, $dblink) {
        $wi = $result->fetch_object("WorkItem");
        '@phan-var ?WorkItem $wi';
        if ($wi) {
            $wi->incorporate($dblink);
        }
        return $wi;
    }

    const DBP_QUERY = 0;
    const DBP_INSERT = 1;
    /** @return array{string,list<mixed>} */
    private function dbparts($type) {
        if ($this->serverId !== null) {
            $keys = ["serverId", "root", "workType", "workSubtype"];
            $values = [$this->serverId, $this->root, $this->workType, $this->workSubtype];
        } else {
            $keys = ["workType", "workSubtype"];
            $values = [$this->workType, $this->workSubtype];
        }
        $qp = $type === self::DBP_QUERY ? join("=? and ", $keys) . "=?" : join(",", $keys);
        return [$qp, $values];
    }

    /** @return bool */
    function reload() {
        [$qp, $qv] = $this->dbparts(self::DBP_QUERY);
        $result = Dbl::qe($this->dblink, "select work from WorkItem where {$qp}", ...$qv);
        if (Dbl::is_error($result)) {
            return false;
        }
        $row = $result->fetch_row();
        $this->work = $row[0] ?? "";
        $this->workpos = $this->workendpos = 0;
        $result->close();
        return true;
    }

    /** @return string */
    function id() {
        $id = $this->workType;
        if ($this->workSubtype !== "") {
            $id .= ":{$this->workSubtype}";
        }
        if ($this->serverId !== null) {
            $id = "{$this->serverId}:{$this->root}:{$id}";
        }
        return $id;
    }

    /** @return bool */
    function enqueue() {
        assert(str_starts_with($this->work, "\x1E") && str_ends_with($this->work, "\n"));
        [$qp, $qv] = $this->dbparts(self::DBP_INSERT);
        // XXX retry a few times?
        $result = Dbl::qe($this->dblink, "insert into WorkItem ({$qp}, work, touchedAt, dequeuedAt) values ?v ?U
            on duplicate key update work=concat(WorkItem.work,?U(work))",
            [array_merge($qv, [$this->work, $this->touchedAt, $this->dequeuedAt])]);
        return !Dbl::is_error($result);
    }

    /** @param ?int $len
     * @return bool */
    function dequeue($len = null) {
        $len = $len ?? $this->workpos;
        if ($len < 0) {
            throw new Error("bad dequeue");
        }
        assert($len >= 0
               && $len <= strlen($this->work)
               && ($len === strlen($this->work)
                   || ($this->work[$len - 1] === "\n"
                       && $this->work[$len] === "\x1E")));
        [$qp, $qv] = $this->dbparts(self::DBP_QUERY);
        $result = null;
        if ($len === strlen($this->work)) {
            $result = Dbl::qe_apply($this->dblink,
                "delete from WorkItem where {$qp} and work=?",
                array_merge($qv, [$this->work]));
            if ($result->affected_rows === 1) {
                $this->work = "";
                $this->workpos = $this->workendpos = 0;
                return true;
            }
        }
        if ($len === 0) {
            // update touchedAt so observers can tell we ran, even though
            // we were blocked from making progress
            Dbl::qe_apply($this->dblink,
                "update WorkItem set touchedAt=greatest(touchedAt,?) where {$qp}",
                array_merge([Conf::$now], $qv));
            return true;
        }
        $prefix = substr($this->work, 0, $len);
        $result = Dbl::qe_apply($this->dblink,
            "update WorkItem set work=SUBSTR(work FROM ?),
            touchedAt=greatest(touchedAt,?), dequeuedAt=greatest(dequeuedAt,?)
            where {$qp} and LEFT(work, ?)=?",
            array_merge([$len + 1, Conf::$now, Conf::$now], $qv, [$len, $prefix]));
        if ($result->affected_rows === 1) {
            $this->work = substr($this->work, $len);
            $this->workpos = $this->workendpos = 0;
            return true;
        }
        return $this->reload();
    }

    /** @return bool */
    function done() {
        return $this->work === ""
            || $this->workpos >= strlen($this->work);
    }

    /** @return ?object */
    function current() {
        if ($this->done()) {
            return null;
        }
        $start = $this->workpos + ($this->work[$this->workpos] === "\x1E" ? 1 : 0);
        $j = null;
        if ($start >= $this->workendpos) {
            $nl = strpos($this->work, "\n", $start);
            if ($nl === false) {
                $nl = strlen($this->work);
            } else if ($nl === $start) {
                ++$nl;
            } else {
                $j = json_decode(substr($this->work, $start, $nl - $start));
                ++$nl;
            }
            $this->workendpos = $nl;
        } else {
            $j = json_decode(substr($this->work, $start, $this->workendpos - $start));
        }
        return is_object($j) ? $j : null;
    }

    function next() {
        assert($this->workpos < $this->workendpos);
        $this->workpos = $this->workendpos;
    }
}
