<?php
// listactions/la_get.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Get_ListAction {
    static function render(PaperList $pl, Qrequest $qreq, ComponentSet $gex) {
        $sel_opt = ListAction::members_selector_options($gex, "get");
        if (!empty($sel_opt)) {
            // Note that `js-submit-paperlist` JS handler depends on this
            return Ht::select("getfn", $sel_opt, $qreq->getfn,
                              ["class" => "want-focus js-submit-action-info-get w-small-selector ignore-diff"])
                . $pl->action_submit("get", ["formmethod" => "get", "class" => "can-submit-all"]);
        } else {
            return null;
        }
    }
}
