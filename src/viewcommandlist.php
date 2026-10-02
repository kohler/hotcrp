<?php
// viewcommandlist.php -- HotCRP class for search view command lists
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

// A search term's view commands: its own and, by reference, its children's.
// Appending a child's list is O(1), so annotations float through deep searches
// in linear time; `flatten` produces the commands in source order.
class ViewCommandList {
    /** @var list<ViewCommand|ViewCommandList> */
    private $items = [];
    /** @var bool */
    private $strip_sorts = false;

    /** @return ViewCommandList */
    static function make_strip_sorts(ViewCommandList $vl) {
        $svl = new ViewCommandList;
        $svl->items[] = $vl;
        $svl->strip_sorts = true;
        return $svl;
    }

    /** @param ViewCommand|ViewCommandList $x
     * @return $this */
    function append($x) {
        $this->items[] = $x;
        return $this;
    }

    /** @return list<ViewCommand> */
    function flatten() {
        $res = [];
        $stack = [[$this, 0, $this->strip_sorts]];
        while (!empty($stack)) {
            $n = count($stack) - 1;
            [$vl, $i, $strip] = $stack[$n];
            if ($i === count($vl->items)) {
                array_pop($stack);
                continue;
            }
            $stack[$n][1] = $i + 1;
            $x = $vl->items[$i];
            if ($x instanceof ViewCommandList) {
                $stack[] = [$x, 0, $strip || $x->strip_sorts];
            } else if (!$strip || !$x->is_sort()) {
                $res[] = $x;
            }
        }
        return $res;
    }
}
