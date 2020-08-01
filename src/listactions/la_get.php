<?php
// listactions/la_get.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Get_ListAction {
    static function render(PaperList $pl, Qrequest $qreq, GroupedExtensions $gex) {
        $last_group = null;
        $sel_opt = [];
        foreach ($gex->members("get") as $rf) {
            if (!str_starts_with($rf->name, "__")) {
                assert(!!$rf->get);
                $as = strpos($rf->title, "/");
                if ($as === false) {
                    if ($last_group) {
                        $sel_opt[] = ["optgroup", false];
                    }
                    $last_group = null;
                    $sel_opt[] = ["value" => substr($rf->name, 4), "label" => $rf->title];
                } else {
                    $group = substr($rf->title, 0, $as);
                    if ($group !== $last_group) {
                        $sel_opt[] = ["optgroup", $group];
                        $last_group = $group;
                    }
                    $sel_opt[] = ["value" => substr($rf->name, 4), "label" => substr($rf->title, $as + 1)];
                }
            }
        }
        if (!empty($sel_opt)) {
            // Note that `js-submit-paperlist` JS handler depends on this
            return Ht::select("getfn", $sel_opt, $qreq->getfn,
                              ["class" => "want-focus js-submit-action-info-get", "style" => "max-width:10em"])
                . "&nbsp; " . Ht::submit("fn", "Go", ["value" => "get", "data-default-submit-all" => 1, "class" => "uic js-submit-mark"]);
        } else {
            return null;
        }
    }
}
