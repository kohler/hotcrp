<?php
// databaseidrandomizer.php -- HotCRP class to randomize database IDs
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class DatabaseIDRandomizer {
    /** @var Conf
     * @readonly */
    private $conf;
    /** @var array<string,list<int>> */
    private $ids = [];
    /** @var int */
    private $batch = 10;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param string $table
     * @param string $id_col
     * @param int $factor
     * @return int */
    function available_id($table, $id_col, $factor = 3) {
        $key = "{$table}.{$id_col}";
        while (empty($this->ids[$key])) {
            // choose a batch of IDs
            $this->batch = min(100, $this->batch * 2);
            $n = max(100, $factor * $this->conf->fetch_ivalue("select count(*) from {$table}"));
            $ids = [];
            while (count($ids) < $this->batch) {
                $ids[] = mt_rand(1, $n);
            }
            $ids = array_values(array_unique($ids));

            // remove IDs that already exist
            $result = $this->conf->qe("select {$id_col} from {$table} where {$id_col}?a", $ids);
            while (($row = $result->fetch_row())) {
                array_splice($ids, array_search((int) $row[0], $ids, true), 1);
            }
            $result->close();

            $this->ids[$key] = $ids;
        }
        return array_pop($this->ids[$key]);
    }

    /** @param string $table
     * @param string $id_col
     * @param array<string,mixed> $fields
     * @param int $factor
     * @return int */
    function insert($table, $id_col, $fields, $factor = 3) {
        $random = $this->conf->setting("random_pids");
        $id = $fields[$id_col] ?? null;
        while (true) {
            if ($id === null && $random) {
                $id = $this->available_id($table, $id_col, $factor);
            }
            if ($id === null) {
                unset($fields[$id_col]);
                $result = $this->conf->qe("insert into {$table} (" . join(",", array_keys($fields)) . ") values ?v", [array_values($fields)]);
            } else {
                $fields[$id_col] = $id;
                $result = $this->conf->qe("insert into {$table} (" . join(",", array_keys($fields)) . ") values ?v on duplicate key update {$id_col}={$id_col}", [array_values($fields)]);
            }
            if ($result->affected_rows > 0) {
                return $result->insert_id;
            }
            $id = null;
        }
    }
}
