<?php
// paperidset.php -- HotCRP ordered set of paper IDs
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

/** A run of paper IDs. `first` is inclusive and `last` exclusive;
 * `last === PHP_INT_MAX` means the run is open-ended. */
final class PaperIDSetRange {
    /** @var int */
    public $first;
    /** @var int */
    public $last;
    /** @var bool
     * True if the IDs were added in descending order. */
    public $rev;
    /** @var bool
     * True if the user named these IDs individually rather than as a range. */
    public $explicit;
    /** @var int
     * Position of the owning segment in order of addition. */
    public $index;

    /** @param int $first
     * @param int $last
     * @param bool $rev
     * @param bool $explicit
     * @param int $index */
    function __construct($first, $last, $rev, $explicit, $index) {
        $this->first = $first;
        $this->last = $last;
        $this->rev = $rev;
        $this->explicit = $explicit;
        $this->index = $index;
    }

    /** @return int */
    function last_inclusive() {
        return $this->last - ($this->last === PHP_INT_MAX ? 0 : 1);
    }

    /** @return bool */
    function is_open() {
        return $this->last === PHP_INT_MAX;
    }

    /** @return bool */
    function is_singleton() {
        return $this->last === $this->first + 1;
    }

    /** @param int $p
     * @return bool */
    function contains($p) {
        return $p >= $this->first && $p < $this->last;
    }

    /** @return int */
    function length() {
        return $this->last === PHP_INT_MAX ? PHP_INT_MAX : $this->last - $this->first;
    }

    /** @return string */
    function unparse() {
        if ($this->last === PHP_INT_MAX) {
            return "{$this->first}-";
        } else if ($this->last === $this->first + 1) {
            return (string) $this->first;
        } else if ($this->explicit && $this->rev) {
            return join(" ", range($this->last - 1, $this->first, -1));
        } else if ($this->explicit) {
            return join(" ", range($this->first, $this->last - 1, 1));
        } else if ($this->rev) {
            return ($this->last - 1) . "-{$this->first}";
        }
        return "{$this->first}-" . ($this->last - 1);
    }
}

/** An ordered set of paper IDs.
 *
 * IDs are added as ranges (`add_range`); `compare()` orders two IDs by
 * the first time they were added. A user who asks for `1 3 5 2 4`, or for
 * `10-1`, can get papers back in that order.
 *
 * Adding is O(1). The first query after a change sorts the additions and
 * resolves overlaps in O(n log n); later queries are O(log n) or better.
 * Ranges are never expanded, so `1-1000000` costs the same as `1-2`.
 * Sequentially-added IDs (or reverse-sequentially-added IDs) can be
 * coalesced.
 */
final class PaperIDSet implements Countable {
    /** @var list<PaperIDSetRange>
     * Segments in order of addition. */
    private $segs = [];
    /** @var ?list<PaperIDSetRange>
     * Sorted, disjoint pieces of the set. Each piece is a narrowed copy of
     * the segment that first added its IDs. Null when out of date. */
    private $pieces;
    /** @var int */
    private $n = 0;
    /** @var bool */
    private $sorted = true;

    /** Add the IDs from `$p0` through `$p1`, inclusive. If `$p0 > $p1`,
     * the IDs are added in descending order. `$explicit` marks IDs the user
     * named individually rather than as part of a range.
     * @param int $p0
     * @param int $p1
     * @param bool $explicit
     * @return $this */
    function add_range($p0, $p1, $explicit = false) {
        $rev = $p0 > $p1;
        if ($rev) {
            [$p0, $p1] = [$p1, $p0];
        }
        $last = $p1 === PHP_INT_MAX ? PHP_INT_MAX : $p1 + 1;
        $mseg = null;
        if (!empty($this->segs)) {
            $mseg = $this->segs[count($this->segs) - 1];
            if ($mseg->explicit !== $explicit) {
                $mseg = null;
            }
        }
        if ($mseg
            && !$rev
            && !$mseg->rev
            && $mseg->first <= $p0
            && $p0 <= $mseg->last) {
            // ascending continuation
            $mseg->last = max($mseg->last, $last);
        } else if ($mseg
                   && ($rev || $p0 === $p1)
                   && ($mseg->rev || $mseg->is_singleton())
                   && $mseg->first <= $last
                   && $last <= $mseg->last) {
            // descending continuation
            $mseg->first = min($mseg->first, $p0);
            $mseg->rev = true;
            $this->sorted = false;
        } else {
            if ($rev
                || ($this->sorted
                    && !empty($this->segs)
                    && $p0 < $this->segs[count($this->segs) - 1]->last)) {
                $this->sorted = false;
            }
            $this->segs[] = new PaperIDSetRange($p0, $last, $rev, $explicit, count($this->segs));
        }
        $this->pieces = null;
        return $this;
    }

    /** @param int $p
     * @param bool $explicit
     * @return $this */
    function add($p, $explicit = false) {
        return $this->add_range($p, $p, $explicit);
    }

    /** @param iterable<int> $pids
     * @param bool $explicit
     * @return $this */
    function add_list($pids, $explicit = false) {
        foreach ($pids as $p) {
            $this->add_range($p, $p, $explicit);
        }
        return $this;
    }

    /** Add every ID in `$set`, after the IDs already present.
     * @return $this */
    function merge(PaperIDSet $set) {
        foreach ($set->segs as $seg) {
            if ($seg->rev) {
                $this->add_range($seg->last_inclusive(), $seg->first, $seg->explicit);
            } else {
                $this->add_range($seg->first, $seg->last_inclusive(), $seg->explicit);
            }
        }
        $this->pieces = null;
        return $this;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->segs);
    }

    /** @return bool */
    function is_sorted() {
        return $this->sorted;
    }


    /** Cut the segments into sorted, disjoint pieces, each owned by the
     * segment that first added its IDs. */
    private function finalize() {
        if ($this->pieces !== null) {
            return;
        }
        $firsts = [];
        foreach ($this->segs as $seg) {
            $firsts[] = $seg->first;
        }
        asort($firsts);

        // Sweep left to right. `$heap` holds the indexes of segments that
        // have started; the smallest index among those not yet finished
        // owns the IDs at the sweep position `$x`. `$emax` is the end of
        // the explicit segments that have started; IDs below it are
        // explicit whatever their owner.
        $heap = new SplMinHeap;
        $pieces = [];
        $x = 0;
        $emax = 0;
        foreach ($firsts as $k => $first) {
            $this->cut_pieces($pieces, $heap, $emax, $x, $first);
            $x = $first;
            $heap->insert($k);
            if ($this->segs[$k]->explicit) {
                $emax = max($emax, $this->segs[$k]->last);
            }
        }
        $this->cut_pieces($pieces, $heap, $emax, $x, PHP_INT_MAX);

        $n = 0;
        foreach ($pieces as $pc) {
            $n += min($pc->length(), PHP_INT_MAX - $n);
        }
        $this->pieces = $pieces;
        $this->n = $n;
    }

    /** Emit pieces covering `[$x, $end)`, taking owners from `$heap`.
     * @param list<PaperIDSetRange> &$pieces
     * @param SplMinHeap $heap
     * @param int $emax
     * @param int $x
     * @param int $end */
    private function cut_pieces(&$pieces, $heap, $emax, $x, $end) {
        while ($x < $end && !$heap->isEmpty()) {
            $owner = $this->segs[$heap->top()];
            if ($owner->last <= $x) {
                $heap->extract();
                continue;
            }
            $plast = min($owner->last, $end);
            $explicit = $x < $emax;
            if ($explicit && $emax < $plast) {
                $plast = $emax;
            }
            $np = count($pieces);
            if ($np > 0
                && $pieces[$np - 1]->index === $owner->index
                && $pieces[$np - 1]->explicit === $explicit
                && $pieces[$np - 1]->last === $x) {
                $pieces[$np - 1]->last = $plast;
            } else {
                $pc = clone $owner;
                $pc->first = $x;
                $pc->last = $plast;
                $pc->explicit = $explicit;
                $pieces[] = $pc;
            }
            $x = $plast;
        }
    }

    /** @param int $p
     * @return ?PaperIDSetRange */
    private function piece($p) {
        $l = 0;
        $r = count($this->pieces);
        while ($l < $r) {
            $m = $l + (($r - $l) >> 1);
            $pc = $this->pieces[$m];
            if ($p < $pc->first) {
                $r = $m;
            } else if ($p >= $pc->last) {
                $l = $m + 1;
            } else {
                return $pc;
            }
        }
        return null;
    }


    /** @param int $p
     * @return bool */
    function contains($p) {
        $this->finalize();
        return $this->piece($p) !== null;
    }

    /** Compare two IDs by first occurrence. IDs not in the set sort last.
     * @param int $a
     * @param int $b
     * @return -1|0|1 */
    function compare($a, $b) {
        $this->finalize();
        $pa = $this->piece($a);
        $pb = $this->piece($b);
        if (!$pa || !$pb) {
            return ($pa ? 0 : 1) <=> ($pb ? 0 : 1) ? : $a <=> $b;
        } else if ($pa->index !== $pb->index) {
            return $pa->index <=> $pb->index;
        }
        return $pa->rev ? $b <=> $a : $a <=> $b;
    }

    /** Number of distinct IDs, or PHP_INT_MAX for an open-ended set.
     * @return int */
    function count(): int {
        $this->finalize();
        return $this->n;
    }

    /** The set as sorted, disjoint ranges.
     * @return list<PaperIDSetRange> */
    function ranges() {
        $this->finalize();
        return $this->pieces;
    }

    /** The set as disjoint ranges in order of first addition. A reversed
     * range runs downward.
     * @return list<PaperIDSetRange> */
    function ordered_ranges() {
        $this->finalize();
        $pieces = $this->pieces;
        usort($pieces, function ($a, $b) {
            return $a->index <=> $b->index
                ? : ($a->rev ? $b->first <=> $a->first : $a->first <=> $b->first);
        });
        return $pieces;
    }

    /** The set as an ascending list of IDs, or null if it has more than
     * `$max` members.
     * @param int $max
     * @return ?list<int> */
    function ids($max = 1000) {
        $this->finalize();
        if ($this->n > $max) {
            return null;
        }
        $ids = [];
        foreach ($this->pieces as $pc) {
            for ($p = $pc->first; $p !== $pc->last; ++$p) {
                $ids[] = $p;
            }
        }
        return $ids;
    }

    /** Check whether a PaperIDSet contains the same IDs as another (or
     * as a list of ints).
     * @param list<int>|PaperIDSet $x
     * @return bool */
    function equal_contents($x) {
        if (is_array($x)) {
            if ($this->count() !== count($x)) {
                return false;
            }
            sort($x);
            $i = 0;
            foreach ($this->pieces as $pc) {
                for ($p = $pc->first; $p !== $pc->last; ++$p) {
                    if (($x[$i] ?? null) !== $p) {
                        return false;
                    }
                    ++$i;
                }
            }
            return true;
        }
        if ($this->count() !== $x->count()) {
            return false;
        } else if ($this->is_empty()) {
            return true;
        }
        $p = PHP_INT_MIN;
        $ai = $xi = 0;
        $an = count($this->pieces);
        while ($ai !== $an) {
            $ap = max($this->pieces[$ai]->first, $p);
            $xp = max($x->pieces[$xi]->first, $p);
            if ($ap !== $xp) {
                return false;
            }
            $p = min($this->pieces[$ai]->last, $x->pieces[$xi]->last);
            if ($this->pieces[$ai]->last === $p) {
                ++$ai;
            }
            if ($x->pieces[$xi]->last === $p) {
                ++$xi;
            }
        }
        return true;
    }

    /** An SQL expression true iff `$field` is in the set.
     * @param string $field
     * @return string */
    function sql_predicate($field) {
        $this->finalize();
        if (empty($this->pieces)) {
            return "false";
        }
        if ($this->n <= 8 * count($this->pieces)
            && ($ids = $this->ids(1000)) !== null) {
            return $field . sql_in_int_list($ids);
        }
        $ids = $s = [];
        $pi = 0;
        $np = count($this->pieces);
        while ($pi !== $np) {
            $first = $this->pieces[$pi]->first;
            $last = $this->pieces[$pi]->last;
            for (++$pi; $pi !== $np && $this->pieces[$pi]->first === $last; ++$pi) {
                $last = $this->pieces[$pi]->last;
            }
            if ($last === PHP_INT_MAX) {
                $s[] = "{$field}>={$first}";
            } else if ($last - $first === 1) {
                $ids[] = $first;
            } else {
                $s[] = "{$field} between {$first} and " . ($last - 1);
            }
        }
        if (!empty($ids)) {
            $s[] = $field . sql_in_int_list($ids);
        }
        return count($s) === 1 ? $s[0] : "(" . join(" or ", $s) . ")";
    }

    /** The set as ranges in order of first addition, e.g. `10-1 12 15-`.
     * @return string */
    function unparse() {
        $s = [];
        foreach ($this->ordered_ranges() as $pc) {
            $s[] = $pc->unparse();
        }
        return join(" ", $s);
    }

    /** The set in `SessionList` ID encoding, in order of first addition,
     * or null if the set is open-ended.
     * @return ?string */
    function encode_ids() {
        $rs = [];
        foreach ($this->ordered_ranges() as $pc) {
            if ($pc->is_open()) {
                return null;
            } else if ($pc->rev) {
                $rs[] = [$pc->last - 1, $pc->first];
            } else {
                $rs[] = [$pc->first, $pc->last - 1];
            }
        }
        return SessionList::encode_ranges($rs);
    }

    /** @return PaperIDSet */
    function sorted() {
        $this->finalize();
        $pidset = new PaperIDSet;
        foreach ($this->pieces as $p) {
            $pidset->add_range($p->first, $p->last_inclusive(), $p->explicit);
        }
        return $pidset;
    }
}
