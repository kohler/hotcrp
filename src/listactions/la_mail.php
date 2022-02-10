<?php
// listactions/la_mail.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Mail_ListAction extends ListAction {
    private $template;
    private $recipients;
    function __construct(Conf $conf, $uf) {
        $this->template = $uf->mail_template ?? null;
        $this->recipients = $uf->recipients ?? null;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager() && $qreq->page() !== "reviewprefs";
    }
    static function render(PaperList $pl, Qrequest $qreq, ComponentSet $gex) {
        $sel_opt = ListAction::members_selector_options($gex, "mail");
        if (!empty($sel_opt)) {
            return Ht::select("mailfn", $sel_opt, $qreq->mailfn,
                              ["class" => "want-focus js-submit-action-info-mail ignore-diff"])
                . $pl->action_submit("mail", ["class" => "can-submit-all", "formmethod" => "get"]);
        } else {
            return null;
        }
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $args = [];
        if ($ssel->equals_search(new PaperSearch($user, $qreq))) {
            $args["q"] = $qreq->q;
            $args["plimit"] = 1;
        } else {
            $args["p"] = join(" ", $ssel->selection());
        }
        $args["t"] = $qreq->t;
        $args["template"] = $this->template;
        $args["to"] = $this->recipients;
        return new Redirection($user->conf->hoturl("mail", $args));
    }
}
