<?php
// databaseidrandomizer.php -- HotCRP class to randomize database IDs
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class DatabaseIDRandomizer {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var array<int,list<int>> */
    private $ids = [];
    /** @var int */
    private $batch = 10;
    /** @var array<int,list<int>> */
    private $reservations = [];

    const PAPERID = 0;
    const REVIEWID = 1;
    static private $tinfo = [
        ["Paper", "paperId"], ["PaperReview", "reviewId"]
    ];

    function __construct(Conf $conf) {
        $this->conf = $conf;
        register_shutdown_function([$this, "cleanup"]);
    }

    /** @param 0|1 $type
     * @param int $factor
     * @return int */
    function available_id($type, $factor = 3) {
        list($table, $id_col) = self::$tinfo[$type];
        while (empty($this->ids[$type])) {
            // choose a batch of IDs
            $this->batch = min(100, $this->batch * 2);
            $n = max(100, $factor * $this->conf->fetch_ivalue("select count(*) from {$table}"));
            $ids = [];
            while (count($ids) < $this->batch) {
                $ids[] = mt_rand(1, $n);
            }
            $sorted_ids = $ids = array_values(array_unique($ids));

            // remove IDs that already exist
            sort($sorted_ids);
            $result = $this->conf->qe("select {$id_col} from {$table} where {$id_col}?a union select id from IDReservation where type=? and id?a", $sorted_ids, $type, $sorted_ids);
            while (($row = $result->fetch_row())) {
                if (($p = array_search((int) $row[0], $ids, true)) !== false) {
                    array_splice($ids, $p, 1);
                }
            }
            $result->close();

            $this->ids[$type] = $ids;
        }
        return array_pop($this->ids[$type]);
    }

    /** @param 0|1 $type
     * @param array<string,mixed> $fields
     * @param int $factor
     * @return int */
    function insert($type, $fields, $factor = 3) {
        list($table, $id_col) = self::$tinfo[$type];
        $random = $this->conf->setting("random_pids");
        $id = $fields[$id_col] ?? null;
        while (true) {
            if ($id === null && $random) {
                $id = $this->available_id($type, $factor);
            }
            if ($id === null) {
                unset($fields[$id_col]);
                $result = $this->conf->qe("insert into {$table} (" . join(",", array_keys($fields)) . ") values ?v", [array_values($fields)]);
            } else {
                $fields[$id_col] = $id;
                $result = $this->conf->qe("insert into {$table} (" . join(",", array_keys($fields)) . ") values ?v on duplicate key update {$id_col}={$id_col}", [array_values($fields)]);
            }
            $id = $result->affected_rows > 0 ? $result->insert_id : null;
            $result->close();
            if ($id !== null) {
                return $id;
            }
        }
    }

    /** @param 0|1 $type
     * @param int $factor
     * @return int */
    function reserve($type, $factor = 3) {
        list($table, $id_col) = self::$tinfo[$type];
        $random = $this->conf->setting("random_pids");
        while (true) {
            if ($random) {
                $wantid = $this->available_id($type, $factor);
                $result = $this->conf->qe("insert into IDReservation set type=?, id=?, timestamp=? on duplicate key update id=id",
                    $type, $wantid, Conf::$now);
                $id = $result->affected_rows > 0 ? $wantid : null;
                $result->close();
            } else {
                $mresult = Dbl::multi_qe($this->conf->dblink,
                    "insert into IDReservation (type,id,timestamp)
                    select ?, greatest((select coalesce(max({$id_col}),0) from {$table}), coalesce(max(id),0))+1, ?
                    from IDReservation where type=?;
                    select id from IDReservation where uid=last_insert_id()",
                    $type, Conf::$now, $type);
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
                $this->reservations[$type][] = $id;
                return $id;
            }
        }
    }

    function cleanup() {
        foreach ($this->reservations as $type => $ids) {
            $this->conf->qe("delete from IDReservation where type=? and id?a", $type, $ids);
        }
        $this->reservations = [];
    }
}
