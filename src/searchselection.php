<?php
// searchselection.php -- HotCRP helper class for paper selections
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchSelection {
    private $sel = array();
    private $selmap = null;

    public function __construct($papers = null) {
        if ($papers) {
            $selmap = [];
            foreach ($papers as $pid)
                if (($pid = cvtint($pid)) > 0 && !isset($selmap[$pid]))
                    $this->sel[] = $selmap[$pid] = $pid;
        }
    }

    static public function make($qreq, Contact $user = null, $key = null) {
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
        } else if ($ps === "all")
            $ps = null;
        if (is_string($ps))
            $ps = preg_split('/\s+/', $ps);
        return new SearchSelection($ps);
    }

    static public function clear_request() {
        unset($_REQUEST["p"], $_REQUEST["pap"], $_GET["p"], $_GET["pap"],
              $_POST["p"], $_POST["pap"]);
    }

    public function is_empty() {
        return empty($this->sel);
    }

    public function count() {
        return count($this->sel);
    }

    public function selection() {
        return $this->sel;
    }

    public function selection_at($i) {
        return get($this->sel, $i);
    }

    public function selection_map() {
        if ($this->selmap === null) {
            $this->selmap = array();
            foreach ($this->sel as $i => $pid)
                $this->selmap[$pid] = $i + 1;
        }
        return $this->selmap;
    }

    public function selection_index($pid) {
        return get_i($this->selection_map(), $pid) - 1;
    }

    public function sort_selection() {
        sort($this->sel);
        $this->selmap = null;
    }

    public function equals_search($search) {
        if ($search instanceof PaperSearch)
            $search = $search->paperList();
        if (count($search) !== count($this->sel))
            return false;
        sort($search);
        $sel = $this->sel;
        sort($sel);
        return join(" ", $search) === join(" ", $sel);
    }

    public function sql_predicate() {
        return sql_in_numeric_set($this->sel);
    }

    public function request_value() {
        return join(" ", $this->sel);
    }

    public function reorder($a) {
        $ax = array();
        foreach ($this->sel as $pid)
            if (array_key_exists($pid, $a))
                $ax[$pid] = $a[$pid];
        return $ax;
    }
}
