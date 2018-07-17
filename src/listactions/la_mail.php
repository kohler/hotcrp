<?php
// listactions/la_mail.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Mail_ListAction extends ListAction {
    function allow(Contact $user) {
        return $user->is_manager() && Navigation::page() !== "reviewprefs";
    }
    static function render(PaperList $pl) {
        return [Ht::select("recipients", array("au" => "Contact authors", "rev" => "Reviewers"), $pl->qreq->recipients, ["class" => "want-focus"])
            . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "mail", "data-default-submit-all" => 1, "class" => "btn uix js-submit-mark"])];
    }
    function run(Contact $user, $qreq, $ssel) {
        $r = in_array($qreq->recipients, ["au", "rev"]) ? $qreq->recipients : "all";
        if ($ssel->equals_search(new PaperSearch($user, $qreq)))
            $x = "q=" . urlencode($qreq->q) . "&plimit=1";
        else
            $x = "p=" . join("+", $ssel->selection());
        go(hoturl("mail", $x . "&t=" . urlencode($qreq->t) . "&recipients=$r"));
    }
}
