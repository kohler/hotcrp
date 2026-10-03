<?php
// searchselection.php -- HotCRP helper class for paper selections
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchSelection {
    /** Paper selections larger than this are limited to existing IDs. */
    const MAX_UNCHECKED_PAPERS = 5000;

    /** @var PaperIDSet */
    private $pidset;
    /** @var bool */
    private $default;
    /** @var ?Conf */
    private $papers_conf;
    /** @var bool */
    private $papers_checked = false;

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
    static function make_papers($qreq, Contact $user, $key = null) {
        $key = $key ?? ($qreq->has("p") ? "p" : "pap");
        if ($qreq->has_a($key)) {
            $ps = $qreq->get_a($key);
        } else if ($qreq->get($key) === "all") {
            $ps = (new PaperSearch($user, $qreq))->sorted_paper_ids();
        } else if ($qreq->has($key)) {
            $ps = SessionList::decode_ids($qreq->get($key), true);
        } else {
            $ps = null;
        }
        $ss = new SearchSelection($ps);
        $ss->papers_conf = $user->conf;
        $ss->pidset = $ss->pidset->clamped(1, PHP_INT_MAX);
        return $ss;
    }

    /** @return SearchSelection */
    static function make_default(Qrequest $qreq, Contact $user) {
        $ss = new SearchSelection((new PaperSearch($user, $qreq))->sorted_paper_ids());
        $ss->default = true;
        $ss->papers_conf = $user->conf;
        return $ss;
    }

    /** @param Qrequest $qreq
     * @param ?string $key
     * @return SearchSelection */
    static function make_raw($qreq, $key = null) {
        $key = $key ?? ($qreq->has("p") ? "p" : "pap");
        if ($qreq->has_a($key)) {
            $ps = $qreq->get_a($key);
        } else if ($qreq->has($key)) {
            $ps = SessionList::decode_ids($qreq->get($key), true);
        } else {
            $ps = null;
        }
        return new SearchSelection($ps);
    }

    /** @param Qrequest $qreq
     * @param ?Contact $user
     * @param ?string $key
     * @return SearchSelection
     * @deprecated */
    static function make($qreq, ?Contact $user = null, $key = null) {
        if (!$user) {
            return self::make_raw($qreq, $key);
        }
        return self::make_papers($qreq, $user, $key);
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

    /** @return PaperIDSet */
    function id_set() {
        return $this->pidset;
    }

    /** @return list<int> */
    function selection() {
        if ($this->papers_conf
            && !$this->papers_checked
            && $this->pidset->count() > self::MAX_UNCHECKED_PAPERS) {
            // a large range: limit it to IDs that could exist
            $maxpid = $this->papers_conf->fetch_ivalue("select coalesce(max(paperId),0) from Paper");
            $this->pidset = $this->pidset->clamped(1, $maxpid);
            $this->papers_checked = true;
        }
        return $this->pidset->ids(100000) ?? [];
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
