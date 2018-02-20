<?php
// searchselection.php -- HotCRP helper class for paper selections
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class SearchSelection {
    private $sel = array();
    private $selmap = null;

    function __construct($papers = null) {
        if ($papers) {
            $selmap = [];
            foreach ($papers as $pid)
                if (($pid = cvtint($pid)) > 0 && !isset($selmap[$pid]))
                    $this->sel[] = $selmap[$pid] = $pid;
        }
    }

    static function make($qreq, Contact $user = null, $key = null) {
        $ps = null;
        if ($key !== null) {
            $ps = $qreq->get_a($key);
        } else {
            $ps = $qreq->get_a(isset($qreq["p"]) ? "p" : "pap");
        }
        if ($user && $ps === "all") {
            $ps = (new PaperSearch($user, $qreq))->sorted_paper_ids();
        } else if ($ps === "all")
            $ps = null;
        if (is_string($ps))
            $ps = preg_split('/\s+/', $ps);
        return new SearchSelection($ps);
    }

    static function clear_request(Qrequest $qreq) {
        unset($qreq->p, $qreq->pap, $_GET["p"], $_GET["pap"], $_POST["p"], $_POST["pap"]);
    }

    function is_empty() {
        return empty($this->sel);
    }

    function count() {
        return count($this->sel);
    }

    function selection() {
        return $this->sel;
    }

    function selection_at($i) {
        return get($this->sel, $i);
    }

    function selection_map() {
        if ($this->selmap === null) {
            $this->selmap = array();
            foreach ($this->sel as $i => $pid)
                $this->selmap[$pid] = $i + 1;
        }
        return $this->selmap;
    }

    function is_selected($pid) {
        if ($this->selmap === null)
            $this->selection_map();
        return isset($this->selmap[$pid]);
    }

    function selection_index($pid) {
        return get($this->selection_map(), $pid, 0) - 1;
    }

    function sort_selection() {
        sort($this->sel);
        $this->selmap = null;
    }

    function equals_search($search) {
        if ($search instanceof PaperSearch)
            $search = $search->paper_ids();
        if (count($search) !== count($this->sel))
            return false;
        sort($search);
        $sel = $this->sel;
        sort($sel);
        return join(" ", $search) === join(" ", $sel);
    }

    function sql_predicate() {
        return sql_in_numeric_set($this->sel);
    }

    function request_value() {
        return join(" ", $this->sel);
    }

    function reorder($a) {
        $ax = array();
        foreach ($this->sel as $pid)
            if (array_key_exists($pid, $a))
                $ax[$pid] = $a[$pid];
        return $ax;
    }
}
