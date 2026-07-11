<?php
// databaseidrandomizer.php -- HotCRP class to randomize database IDs
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class DatabaseIDRandomizer_Type {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var 0|1|2
     * @readonly */
    private $type;
    /** @var string
     * @readonly */
    public $table;
    /** @var string
     * @readonly */
    public $id_column;
    /** @var null|int|float
     * @readonly */
    public $default_factor;
    /** @var ?int
     * @readonly */
    public $max;
    /** @var bool */
    public $random;
    /** @var int */
    public $batch;
    /** @var list<int> */
    private $ids = [];
    /** @var list<int> */
    private $reservations = [];

    /** @param 0|1|2 $type */
    function __construct(Conf $conf, $type) {
        $this->conf = $conf;
        $this->type = $type;
        if ($type === DatabaseIDRandomizer::PAPERID) {
            $this->table = "Paper";
            $this->id_column = "paperId";
            $this->default_factor = 3;
        } else if ($type === DatabaseIDRandomizer::REVIEWID) {
            $this->table = "PaperReview";
            $this->id_column = "reviewId";
            $this->default_factor = 5;
        } else {
            $this->table = "PaperReview";
            $this->id_column = "reviewToken";
            $this->max = 2000000000;
            $this->random = true;
        }
        $this->batch = 10;
        $this->refresh_settings();
        register_shutdown_function([$this, "cleanup"]);
    }

    /** @param ?int $factor
     * @return int */
    function available_id($factor = null) {
        $factor = $factor ?? $this->default_factor;
        while (empty($this->ids)) {
            // choose a batch of IDs
            $this->batch = min(100, $this->batch * 2);
            if ($this->max !== null) {
                $n = $this->max;
            } else {
                $n = max(100, $factor * $this->conf->fetch_ivalue("select count(*) from {$this->table}"));
            }
            while (count($this->ids) < $this->batch) {
                $this->ids[] = mt_rand(1, $n);
            }
            $this->ids = array_values(array_unique($this->ids));

            // remove IDs that already exist
            $sorted_ids = $this->ids;
            sort($sorted_ids);
            $result = $this->conf->qe("select {$this->id_column} from {$this->table} where {$this->id_column}?a union select id from IDReservation where type=? and id?a", $sorted_ids, $this->type, $sorted_ids);
            while (($row = $result->fetch_row())) {
                if (($p = array_search((int) $row[0], $this->ids, true)) !== false) {
                    array_splice($this->ids, $p, 1);
                }
            }
            $result->close();
        }
        return array_pop($this->ids);
    }

    /** @param array<string,mixed> $fields
     * @param ?int $factor
     * @return Dbl_Result */
    function insert($fields, $factor = null) {
        $id = $fields[$this->id_column] ?? null;
        while (true) {
            if ($id === null && $this->random) {
                $id = $this->available_id($factor);
            }
            if ($id === null) {
                unset($fields[$this->id_column]);
                $result = $this->conf->qe("insert into {$this->table} (" . join(",", array_keys($fields)) . ") values ?v", [array_values($fields)]);
            } else {
                $fields[$this->id_column] = $id;
                $result = $this->conf->qe("insert into {$this->table} (" . join(",", array_keys($fields)) . ") values ?v on duplicate key update {$this->id_column}={$this->id_column}", [array_values($fields)]);
            }
            if ($result->affected_rows > 0) {
                if (!$result->insert_id) {
                    $result->insert_id = $id;
                }
                return $result;
            }
            $result->close();
        }
    }

    /** @param ?int $factor
     * @return int */
    function reserve($factor = null) {
        while (true) {
            if ($this->random) {
                $wantid = $this->available_id($factor);
                $result = $this->conf->qe("insert into IDReservation set type=?, id=?, timestamp=? on duplicate key update id=id",
                    $this->type, $wantid, Conf::$now);
                $id = $result->affected_rows > 0 ? $wantid : null;
                $result->close();
            } else {
                $mresult = Dbl::multi_qe($this->conf->dblink,
                    "insert into IDReservation (type,id,timestamp)
                    select ?, greatest((select coalesce(max({$this->id_column}),0) from {$this->table}), coalesce(max(id),0))+1, ?
                    from IDReservation where type=?;
                    select id from IDReservation where uid=last_insert_id()",
                    $this->type, Conf::$now, $this->type);
                $id = null;
                if (($result = $mresult->next())) {
                    $ok = $result->affected_rows > 0;
                    $result->close();
                    if (($result = $mresult->next())) {
                        if ($ok && ($row = $result->fetch_row())) {
                            $id = (int) $row[0];
                        }
                        $result->close();
                    }
                }
            }
            if ($id !== null) {
                $this->reservations[] = $id;
                return $id;
            }
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function refresh_settings() {
        if ($this->type === DatabaseIDRandomizer::PAPERID
            || $this->type === DatabaseIDRandomizer::REVIEWID) {
            $this->random = !!$this->conf->setting("random_pids");
        }
    }

    function cleanup() {
        if (!empty($this->reservations)) {
            $this->conf->qe("delete from IDReservation where type=? and id?a", $this->type, $this->reservations);
        }
        $this->reservations = [];
    }
}

class DatabaseIDRandomizer {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var array<int,DatabaseIDRandomizer_Type> */
    private $tinfo = [];

    const PAPERID = 0;
    const REVIEWID = 1;
    const REVIEWTOKEN = 2;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param 0|1|2 $type
     * @return DatabaseIDRandomizer_Type */
    private function type($type) {
        if (!isset($this->tinfo[$type])) {
            $this->tinfo[$type] = new DatabaseIDRandomizer_Type($this->conf, $type);
        }
        return $this->tinfo[$type];
    }

    /** @param 0|1|2 $type
     * @return bool */
    function want_random_ids($type) {
        return $this->type($type)->random;
    }

    /** @param 0|1|2 $type
     * @param ?int $factor
     * @return int */
    function available_id($type, $factor = null) {
        return $this->type($type)->available_id($factor);
    }

    /** @param 0|1|2 $type
     * @param array<string,mixed> $fields
     * @param ?int $factor
     * @return Dbl_Result */
    function insert($type, $fields, $factor = null) {
        return $this->type($type)->insert($fields, $factor);
    }

    /** @param 0|1|2 $type
     * @param ?int $factor
     * @return int */
    function reserve($type, $factor = null) {
        return $this->type($type)->reserve($factor);
    }

    function refresh_settings() {
        foreach ($this->tinfo as $type) {
            $type->refresh_settings();
        }
    }

    function cleanup() {
        foreach ($this->tinfo as $type) {
            $type->cleanup();
        }
    }
}
