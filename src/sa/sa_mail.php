<?php
// sa/sa_mail.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mail_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
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

SearchActions::register("mail", null, SiteLoader::API_GET | SiteLoader::API_PAPER, new Mail_SearchAction);
