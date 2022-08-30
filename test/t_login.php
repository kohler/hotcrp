<?php
// t_login.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Login_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var UserStatus
     * @readonly */
    public $us1;
    /** @var Contact
     * @readonly */
    public $user_chair;
    /** @var ?\mysqli
     * @readonly */
    public $cdb;

    const MARINA = "marina@poema.ru";

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->us1 = new UserStatus($conf->root_user());
        $this->user_chair = $conf->checked_user_by_email("chair@_.com");
        $this->cdb = $conf->contactdb();
    }

    function test_setup() {
        $removables = ["newuser@_.com", "firstchair@_.com"];
        $this->conf->qe("delete from ContactInfo where email?a", $removables);
        if ($this->cdb !== null) {
            Dbl::qe($this->cdb, "delete from ContactInfo where email?a", $removables);
        }
    }

    function test_login() {
        $email = "newuser@_.com";
        $this->conf->invalidate_caches(["users" => true]);

        $qreq = Qrequest::make_url("newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        $prep = Signin_Page::mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches(["users" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = Qrequest::make_url("resetpassword?email={$email}", "POST");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches(["users" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = Qrequest::make_url("resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));
    }

    function test_login_placeholder() {
        $email = "scapegoat2@baa.com";
        Contact::make_keyed($this->conf, [
            "email" => $email,
            "disablement" => Contact::DISABLEMENT_PLACEHOLDER
        ])->store();

        $this->conf->invalidate_caches(["users" => true]);

        // `newaccount` request
        $qreq = Qrequest::make_url("newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $prep = Signin_Page::mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        // but user is still a placeholder
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disablement, Contact::DISABLEMENT_PLACEHOLDER);

        $this->conf->invalidate_caches(["users" => true]);

        // `resetpassword` request with capability
        $user = Contact::make_email($this->conf, $email);
        $qreq = Qrequest::make_url("resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));

        // user is no longer a placeholder
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disablement, 0);
    }

    function test_login_first_user() {
        $this->conf->save_setting("setupPhase", 1);

        $email = "firstchair@_.com";
        $this->conf->invalidate_caches(["users" => true]);

        $qreq = Qrequest::make_url("newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        $prep = Signin_Page::mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches(["users" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = Qrequest::make_url("resetpassword?email={$email}", "POST");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches(["users" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = Qrequest::make_url("resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));
    }
}
