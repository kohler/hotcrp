<?php
// searchselection.php -- HotCRP helper class for paper selections
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchSelection {
    /** @var list<int> */
    private $sel = [];
    /** @var array<int,int> */
    private $selmap = [];

    function __construct($papers = null) {
        $n = 1;
        foreach ($papers ?? [] as $pid) {
            if (($pid = stoi($pid) ?? -1) > 0
                && !isset($this->selmap[$pid])) {
                $this->sel[] = $pid;
                $this->selmap[$pid] = $n;
                ++$n;
            }
        }
    }

    /** @param Qrequest $qreq
     * @param ?string $key
     * @return SearchSelection */
    static function make($qreq, Contact $user = null, $key = null) {
        $key = $key ?? ($qreq->has("p") ? "p" : "pap");
        if ($qreq->has_a($key)) {
            $ps = $qreq->get_a($key);
        } else if ($qreq->get($key) === "all") {
            $ps = $user ? (new PaperSearch($user, $qreq))->sorted_paper_ids() : null;
        } else if ($qreq->has($key)) {
            $ps = SessionList::decode_ids($qreq->get($key));
        } else {
            $ps = null;
        }
        return new SearchSelection($ps);
    }

    static function clear_request(Qrequest $qreq) {
        unset($qreq->p, $qreq->pap, $_GET["p"], $_GET["pap"], $_POST["p"], $_POST["pap"]);
    }

    /** @return bool */
    function is_empty() {
        return empty($this->sel);
    }

    /** @return int */
    function count() {
        return count($this->sel);
    }

    /** @return list<int> */
    function selection() {
        return $this->sel;
    }

    /** @return ?int */
    function selection_at($i) {
        return $this->sel[$i] ?? null;
    }

    /** @return array<int,int> */
    function selection_map() {
        if ($this->selmap === null) {
            $this->selmap = [];
            foreach ($this->sel as $i => $pid) {
                $this->selmap[$pid] = $i + 1;
            }
        }
        return $this->selmap;
    }

    /** @return PaperInfoSet|Iterable<PaperInfo> */
    function paper_set(Contact $user, $options = []) {
        $options["paperId"] = $this->sel;
        $pset = $user->paper_set($options);
        $pset->sort_by([$this, "order_compare"]);
        return $pset;
    }

    /** @return bool */
    function is_selected($pid) {
        return (($this->selection_map())[$pid] ?? null) !== null;
    }

    /** @return int */
    function selection_index($pid) {
        return (($this->selection_map())[$pid] ?? 0) - 1;
    }

    function sort_selection() {
        sort($this->sel);
        $this->selmap = null;
    }

    /** @param int|PaperInfo $a
     * @param int|PaperInfo $b
     * @return int */
    function order_compare($a, $b) {
        if ($a instanceof PaperInfo) {
            $a = $a->paperId;
        }
        if ($b instanceof PaperInfo) {
            $b = $b->paperId;
        }
        $sm = $this->selection_map();
        $as = $sm[$a] ?? PHP_INT_MAX;
        $bs = $sm[$b] ?? PHP_INT_MAX;
        if ($as === $bs) {
            return $a < $b ? -1 : ($a == $b ? 0 : 1);
        } else {
            return $as < $bs ? -1 : 1;
        }
    }

    /** @return bool */
    function equals_search($search) {
        if ($search instanceof PaperSearch) {
            $search = $search->paper_ids();
        }
        if (count($search) !== count($this->sel)) {
            return false;
        }
        sort($search);
        $sel = $this->sel;
        sort($sel);
        return join(" ", $search) === join(" ", $sel);
    }

    /** @return string */
    function sql_predicate() {
        return sql_in_int_list($this->sel);
    }

    /** @return string */
    function request_value() {
        return join(" ", $this->sel);
    }

    /** @return string */
    function unparse_search() {
        if (empty($this->sel)) {
            return "NONE";
        } else if (count($this->sel) > 100) {
            return "pidcode:" . SessionList::encode_ids($this->sel);
        } else {
            return join(" ", $this->sel);
        }
    }
}
