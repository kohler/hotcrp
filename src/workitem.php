<?php
// workitem.php -- HotCRP class representing work
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WorkItem {
    /** @var Conf */
    public $conf;
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
    private $workpos = 0;
    /** @var int */
    private $workendpos = 0;


    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

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
        $wi = new WorkItem($conf);
        if ($conf->opt("processWorkContactdb")
            && $conf->contactdb()
            && ($serverId = SiteLoader::server_id())) {
            $wi->serverId = $serverId;
            $wi->root = SiteLoader::$root;
        }
        $wi->workType = $workType;
        $wi->workSubtype = $workSubtype ?? "";
        $wi->work = $workstr;
        return $wi;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function incorporate(Conf $conf) {
        $this->conf = $conf;
        if (isset($this->serverId)) {
            $this->serverId = (int) $this->serverId;
        }
    }

    /** @param mysqli_result|Dbl_Result $result
     * @return ?WorkItem */
    static function fetch($result, Conf $conf) {
        $wi = $result->fetch_object("WorkItem", [$conf]);
        '@phan-var ?WorkItem $wi';
        if ($wi) {
            $wi->incorporate($conf);
        }
        return $wi;
    }

    /** @return bool */
    function reload() {
        if ($this->serverId !== null) {
            $result = Dbl::qe($this->conf->contactdb(),
                "select work from WorkItem where serverId=? and root=? and workType=? and workSubtype=?",
                $this->serverId, $this->root, $this->workType, $this->workSubtype);
        } else {
            $result = Dbl::qe($this->conf->dblink,
                "select work from WorkItem where workType=? and workSubtype=?",
                $this->workType, $this->workSubtype);
        }
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
        if ($this->serverId !== null) {
            // XXX retry a few times?
            $result = Dbl::qe($this->conf->contactdb(),
                "insert into WorkItem (serverId, root, workType, workSubtype, work)
                    values (?, ?, ?, ?, ?) ?U on duplicate key update work=concat(work,?U(work))",
                $this->serverId, $this->root, $this->workType, $this->workSubtype, $this->work);
        } else {
            $result = Dbl::qe($this->conf->dblink,
                "insert into WorkItem (workType, workSubtype, work)
                    values (?, ?, ?) ?U on duplicate key update work=concat(work,?U(work))",
                $this->workType, $this->workSubtype, $this->work);
        }
        return !Dbl::is_error($result);
    }

    /** @param ?int $len
     * @return bool */
    function dequeue($len = null) {
        $len = $len ?? $this->workpos;
        if ($len < 0) {
            return $this->reload();
        }
        assert($len >= 0
               && $len <= strlen($this->work)
               && ($len === strlen($this->work)
                   || ($this->work[$len - 1] === "\n"
                       && $this->work[$len] === "\x1E")));
        if ($len === 0) {
            return true;
        }
        $result = null;
        if ($len === strlen($this->work)) {
            if ($this->serverId !== null) {
                $result = Dbl::qe($this->conf->contactdb(),
                    "delete from WorkItem where serverId=? and root=? and workType=? and workSubtype=? and work=?",
                    $this->serverId, $this->root, $this->workType, $this->workSubtype, $this->work);
            } else {
                $result = Dbl::qe($this->conf->dblink,
                    "delete from WorkItem where workType=? and workSubtype=? and work=?",
                    $this->workType, $this->workSubtype, $this->work);
            }
            if ($result->affected_rows === 1) {
                $this->work = null;
                $this->workpos = $this->workendpos = 0;
                return true;
            }
        }
        $prefix = substr($this->work, 0, $len);
        if ($this->serverId !== null) {
            $result = Dbl::qe($this->conf->contactdb(),
                "update WorkItem set work=SUBSTR(work FROM ?)
                where serverId=? and root=? and workType=? and workSubtype=? and LEFT(work, ?)=?",
                $len + 1, $this->serverId, $this->root, $this->workType, $this->workSubtype, $len, $prefix);
        } else {
            $result = Dbl::qe($this->conf->dblink,
                "update WorkItem set work=SUBSTR(work FROM ?)
                where workType=? and workSubtype=? and LEFT(work, ?)=?",
                $len + 1, $this->workType, $this->workSubtype, $len, $prefix);
        }
        if ($result->affected_rows === 1) {
            $this->work = substr($this->work, $len);
            $this->workpos = $this->workendpos = 0;
            return true;
        }
        return $this->reload();
    }

    /** @return bool */
    function done() {
        return $this->work === null
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
