<?php
// t_login.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->us1 = new UserStatus($conf->root_user());
        $this->user_chair = $conf->checked_user_by_email("chair@_.com");
        $this->cdb = $conf->contactdb();

        $removables = ["newuser@hotcrp.com", "scapegoat2@baa.com", "firstchair@hotcrp.com"];
        $this->conf->qe("delete from ContactInfo where email?a", $removables);
        if ($this->cdb !== null) {
            Dbl::qe($this->cdb, "delete from ContactInfo where email?a", $removables);
        }
    }

    function test_login() {
        $email = "newuser@hotcrp.com";
        $this->conf->invalidate_caches("users");

        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("newaccount");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        xassert(!$this->cdb || $u->cdb_user()->contactDbId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches("users");

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches("users");

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));

        if ($this->cdb) {
            $this->conf->invalidate_caches("users");
            $this->conf->qe("delete from ContactInfo where email=?", $email);

            $user = Contact::make($this->conf);
            xassert_eqq($user->contactId, 0);
            xassert_eqq($user->contactDbId, 0);
            $qreq = TestQreq::post(["email" => $email, "password" => "newuserpassword!"])->set_user($user)->set_page("signin");
            $info = LoginHelper::login_info($this->conf, $qreq);
            xassert_eqq($info["ok"], true);
            $info = LoginHelper::login_complete($info, $qreq);
            xassert_eqq($info["ok"], true);
            $user = $info["user"];
            xassert($user instanceof Contact);
            xassert_eqq($user->email, $email);
            xassert_eqq($user->contactId, 0);
            xassert_neqq($user->contactDbId, 0);
            $user->ensure_account_here();
            xassert_neqq($user->contactId, 0);
            xassert_eqq($user->contactDbId, 0);
            xassert(!$user->is_unconfirmed());
        }
    }

    function test_reset_request_with_email() {
        // Entering an *email* (not a reset code) in the `resetpassword` page's
        // reset-code field triggers the forgot-password flow on a freshly
        // constructed inner Qrequest. That inner Qrequest must inherit the
        // outer request's navigation, otherwise the eventual redirect crashes
        // in Qrequest::redirect() (`$this->_navigation->resolve()` on null).
        $email = "chair@_.com";
        $this->conf->invalidate_caches("users");

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("resetcap", $email);
        $this->conf->saved_messages_begin();
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        } finally {
            Navigation::$test_mode = $old_test_mode;
        }
        // With the navigation set on the inner request, the forgot-password
        // flow redirects back to the resetpassword page rather than crashing.
        xassert(!!$result);
        xassert_str_contains($result->url, "resetpassword");
    }

    /** Return the newest password reset token belonging to `$email`, which is
     * the one a just-sent reset mail would carry.
     * @param string $email
     * @return ?TokenInfo */
    private function latest_reset_token($email) {
        $dbs = [];
        if ($this->cdb && ($cdbu = $this->conf->cdb_user_by_email($email))) {
            $dbs[] = [$this->cdb, true, $cdbu->contactDbId];
        }
        if (($u = $this->conf->user_by_email($email))) {
            $dbs[] = [$this->conf->dblink, false, $u->contactId];
        }
        foreach ($dbs as $dbx) {
            $result = Dbl::qe($dbx[0], "select * from Capability where capabilityType=? and contactId=? order by timeCreated desc, salt desc limit 1",
                TokenInfo::RESETPASSWORD, $dbx[2]);
            $tok = TokenInfo::fetch($result, $this->conf, $dbx[1]);
            Dbl::free($result);
            if ($tok) {
                return $tok;
            }
        }
        return null;
    }

    /** A `redirect` given to `signin` should survive the whole detour through
     * account creation and password reset: the reset token carries it across
     * the email hop, and the reset hands it back to `signin`, which delivers
     * the user to the page they originally wanted. */
    function test_redirect_survives_reset() {
        $email = "newuser@hotcrp.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        if ($this->cdb) {
            Dbl::qe($this->cdb, "delete from ContactInfo where email=?", $email);
        }
        $this->conf->invalidate_caches("users");

        // create an account, asking to end up at `paper/1`
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email, "redirect" => "paper/1"])
            ->set_user($user)->set_page("newaccount");
        $result = null;
        try {
            (new Signin_Page)->create_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        // account creation returns to `signin` with the destination intact
        xassert(!!$result);
        xassert_str_contains($result->url, "signin");
        xassert_str_contains(urldecode($result->url), "paper/1");

        // the mailed reset token remembers the destination
        $tok = $this->latest_reset_token($email);
        xassert(!!$tok);
        xassert_str_contains($tok->data("redirect") ?? "", "paper/1");

        $this->conf->invalidate_caches("users");

        // resetting the password sends the user to `signin`, destination intact
        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("resetcap", $tok->salt);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $cs->callable("Signin_Page")->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert_str_contains($result->url, "signin");
        xassert_str_contains(urldecode($result->url), "paper/1");

        // and signing in from there lands on the requested page
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post([
            "email" => $email, "password" => "newuserpassword!", "redirect" => "paper/1"
        ])->set_user($user)->set_page("signin");
        $info = LoginHelper::login_complete(LoginHelper::login_info($this->conf, $qreq), $qreq);
        xassert_eqq($info["ok"], true);
        xassert_str_contains(urldecode($info["redirect"]), "redirect=paper/1");
    }

    function test_login_placeholder() {
        $email = "scapegoat2@baa.com";
        Contact::make_keyed($this->conf, [
            "email" => $email,
            "disablement" => Contact::CF_PLACEHOLDER
        ])->store();
        $user = $this->conf->user_by_email($email);
        xassert($user->is_unconfirmed());

        $this->conf->invalidate_caches("users");

        // `newaccount` request
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email])->set_page("newaccount")->set_user($user);
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        xassert(!$this->cdb || $u->cdb_user()->contactDbId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        // but user is still a placeholder
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert($u->is_unconfirmed());
        if ($this->cdb) {
            $u = $this->conf->checked_cdb_user_by_email($email);
            xassert(!!$u);
            xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        }

        $this->conf->invalidate_caches("users");

        // `resetpassword` request with capability
        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));

        // user is no longer a placeholder, but unconfirmed
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), 0);
        xassert($u->is_unconfirmed());
        if ($this->cdb) {
            $u = $this->conf->checked_cdb_user_by_email($email);
            xassert(!!$u);
            xassert_eqq($u->disabled_flags(), 0);
            xassert($u->is_unconfirmed());
        }

        // logging in confirms user
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email, "password" => "newuserpassword!"])->set_user($user)->set_page("signin");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->signin_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert_str_contains($result->url, "postlogin");
        $u = $this->conf->fresh_user_by_email($email);
        xassert(!$u->is_unconfirmed());
        if ($this->cdb) {
            $u = $this->conf->checked_cdb_user_by_email($email);
            xassert(!$u->is_unconfirmed());
        }
    }

    function test_login_first_user() {
        $email = "firstchair@hotcrp.com";
        $this->conf->save_setting("setupPhase", 1);
        $this->conf->invalidate_caches("users");

        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("newaccount");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches("users");

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches("users");

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);

        $u = user($email);
        xassert($u->check_password("newuserpassword!"));
        xassert($u->privChair);
    }
}
