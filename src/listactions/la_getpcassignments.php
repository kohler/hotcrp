<?php
// listactions/la_getpcassignments.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetPcassignments_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        list($header, $items) = ListAction::pcassignments_csv_data($user, $ssel->selection());
        return $user->conf->make_csvg("pcassignments")->select($header)->append($items);
    }
}
