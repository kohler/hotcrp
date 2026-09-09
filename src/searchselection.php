<?php
// searchselection.php -- HotCRP helper class for paper selections
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchSelection {
    /** @var PaperIDSet */
    private $pidset;
    /** @var bool */
    private $default;

    /** @param null|list<int|array{int,int}>|PaperIDSet $papers */
    function __construct($papers = null) {
        if ($papers instanceof PaperIDSet) {
            $this->pidset = $papers;
        } else {
            $this->pidset = new PaperIDSet;
            foreach ($papers ?? [] as $id) {
                if (is_array($id)) {
                    $this->pidset->add_range($id[0], $id[1]);
                } else if (is_int($id)) {
                    $this->pidset->add($id);
                } else if (($i = stoi($id) ?? -1) > 0) {
                    $this->pidset->add($i);
                }
            }
        }
    }

    /** @param Qrequest $qreq
     * @param ?string $key
     * @return SearchSelection */
    static function make($qreq, ?Contact $user = null, $key = null) {
        $key = $key ?? ($qreq->has("p") ? "p" : "pap");
        if ($qreq->has_a($key)) {
            $ps = $qreq->get_a($key);
        } else if ($qreq->get($key) === "all") {
            $ps = $user ? (new PaperSearch($user, $qreq))->sorted_paper_ids() : null;
        } else if ($qreq->has($key)) {
            $ps = SessionList::decode_ids($qreq->get($key), true);
        } else {
            $ps = null;
        }
        return new SearchSelection($ps);
    }

    /** @return SearchSelection */
    static function make_default(Qrequest $qreq, Contact $user) {
        $ss = new SearchSelection((new PaperSearch($user, $qreq))->sorted_paper_ids());
        $ss->default = true;
        return $ss;
    }

    static function clear_request(Qrequest $qreq) {
        unset($qreq->p, $qreq->pap, $_GET["p"], $_GET["pap"], $_POST["p"], $_POST["pap"]);
    }

    /** @return bool */
    function is_empty() {
        return $this->pidset->is_empty();
    }

    /** @return bool */
    function is_default() {
        return $this->default;
    }

    /** @param bool $default
     * @return $this */
    function set_default($default) {
        $this->default = $default;
        return $this;
    }

    /** @param PaperSearch $srch
     * @return $this */
    function reset_default($srch) {
        $this->default = $this->equals_search($srch);
        return $this;
    }

    /** @return int */
    function count() {
        return $this->pidset->count();
    }

    /** @return list<int> */
    function selection() {
        return $this->pidset->ids(100000);
    }

    /** @return PaperInfoSet|Iterable<PaperInfo> */
    function paper_set(Contact $user, $options = []) {
        $options["paperId"] = $this->pidset;
        $pset = $user->paper_set($options);
        $pset->sort_by([$this, "order_compare"]);
        $pset->apply_filter([$user, "can_view_paper"]);
        return $pset;
    }

    /** @return bool */
    function is_selected($pid) {
        return $this->pidset->contains($pid);
    }

    function sort_selection() {
        $this->pidset = $this->pidset->sorted();
    }

    /** @param int|PaperInfo $a
     * @param int|PaperInfo $b
     * @return int */
    function order_compare($a, $b) {
        return $this->pidset->compare($a instanceof PaperInfo ? $a->paperId : $a,
                                      $b instanceof PaperInfo ? $b->paperId : $b);
    }

    /** @return bool */
    function equals_search($srch) {
        return $this->pidset->equal_contents($srch instanceof PaperSearch ? $srch->paper_ids() : $srch);
    }

    /** @return string */
    function unparse_search() {
        if ($this->pidset->is_empty()) {
            return "NONE";
        } else if ($this->pidset->count() > 100) {
            return "pidcode:" . $this->pidset->encode_ids();
        }
        return join(" ", $this->pidset->ids());
    }
}
