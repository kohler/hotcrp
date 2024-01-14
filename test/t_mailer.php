<?php
// t_mailer.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
            $ms = new MailSender($mr, $qreq);
            $ms->set_template($template);
            $ms->set_no_print(true)->set_send_all(true);
            $ms->prepare_sending_mailid();
            $ms->set_phase(2);
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
}
