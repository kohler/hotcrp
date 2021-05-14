<?php
// listactions/la_get.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Get_ListAction {
    static function render(PaperList $pl, Qrequest $qreq, GroupedExtensions $gex) {
        $sel_opt = ListAction::members_selector_options($gex, "get");
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
