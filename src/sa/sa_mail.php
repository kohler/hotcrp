<?php
// sa/sa_mail.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mail_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager() && Navigation::page() !== "reviewprefs";
    }
    function list_actions(Contact $user, $qreq, PaperList $plist, &$actions) {
        $actions[] = [1000, "mail", "Mail", "<b>:</b> &nbsp;"
            . Ht::select("recipients", array("au" => "Contact authors", "rev" => "Reviewers"), $qreq->recipients, ["class" => "want-focus"])
            . " &nbsp;" . Ht::submit("fn", "Go", ["value" => "mail", "onclick" => "return plist_submit.call(this)", "data-plist-submit-all" => 1])];
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

SearchAction::register("mail", null, SiteLoader::API_GET | SiteLoader::API_PAPER, new Mail_SearchAction);
