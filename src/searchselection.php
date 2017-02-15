<?php
// searchselection.php -- HotCRP helper class for paper selections
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
        if ($key !== null && isset($qreq[$key]))
            $ps = $qreq[$key];
        else if ($key === null && isset($qreq["p"]))
            $ps = $qreq["p"];
        else if ($key === null && isset($qreq["pap"]))
            $ps = $qreq["pap"];
        if ($user && $ps === "all") {
            $s = new PaperSearch($user, $qreq);
            $ps = $s->paperList();
            if ($s->has_sort()) {
                $plist = new PaperList($s);
                $ps = $plist->id_array();
            }
        } else if ($ps === "all")
            $ps = null;
        if (is_string($ps))
            $ps = preg_split('/\s+/', $ps);
        return new SearchSelection($ps);
    }

    static function clear_request() {
        unset($_REQUEST["p"], $_REQUEST["pap"], $_GET["p"], $_GET["pap"],
              $_POST["p"], $_POST["pap"]);
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
            $search = $search->paperList();
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
