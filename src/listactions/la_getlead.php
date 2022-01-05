<?php
// listactions/la_getlead.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetLead_ListAction extends ListAction {
    private $type;
    function __construct($conf, $fj) {
        $this->type = $fj->type;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->isPC;
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $key = $this->type . "ContactId";
        $can_view = "can_view_" . $this->type;
        $texts = [];
        foreach ($ssel->paper_set($user) as $row) {
            if ($row->$key && $user->$can_view($row, true)) {
                $name = $user->name_object_for($row->$key);
                $texts[] = [$row->paperId, $row->title, $name->firstName, $name->lastName, $name->email];
            }
        }
        return $user->conf->make_csvg($this->type . "s")
            ->select(["paper", "title", "first", "last", "{$this->type}email"])
            ->append($texts);
    }
}
