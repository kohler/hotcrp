<?php
// t_mailer.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Mailer_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function run_send_template(MailRecipients $mr, $template, $qreq = []) {
        if (!($qreq instanceof Qrequest)) {
            $qreq = (new Qrequest("POST", $qreq))->set_user($mr->user)->approve_token();
        }
        ob_start();
        try {
            $ms = new MailSender($mr, $qreq, 2);
            $ms->set_template($template);
            $ms->set_no_print(true)->set_send_all(true);
            $ms->prepare_sending_mailid();
            $ms->run();
        } catch (PageCompletion $unused) {
        }
        ob_end_clean();
    }

    function test_send() {
        MailChecker::clear();
        $user = $this->conf->checked_user_by_email("chair@_.com");
        $mr = (new MailRecipients($user))->set_recipients("au")->set_paper_ids([13, 14, 15, 16]);
        $this->run_send_template($mr, "@authors");
        MailChecker::check_db("t_mailer-send-1");
    }

    function test_accept_mail_marks_notified() {
        MailChecker::clear();
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        // accept paper 13 and clear any prior notification mark
        xassert_assign($chair, "paper,action,decision\n13,decision,yes\n");
        $this->conf->qe("update Paper set timeAcceptNotified=0 where paperId=13");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // sending to accept-class authors marks the paper notified
        $mr = (new MailRecipients($chair))->set_recipients("dec:yes")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_gt($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // a second accept mail does not move the timestamp
        $t1 = $this->conf->checked_paper_by_id(13)->timeAcceptNotified;
        $mr = (new MailRecipients($chair))->set_recipients("dec:yes")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, $t1);

        // a generic all-authors mail does NOT mark a non-notified accepted paper
        $this->conf->qe("update Paper set timeAcceptNotified=0 where paperId=13");
        $mr = (new MailRecipients($chair))->set_recipients("au")->set_paper_ids([13]);
        $this->run_send_template($mr, "@authors");
        xassert_eqq($this->conf->checked_paper_by_id(13)->timeAcceptNotified, 0);

        // clean up: clear decision so no papers remain accepted
        xassert_assign($chair, "paper,action,decision\n13,cleardecision,yes\n");
        MailChecker::clear();
    }
}
