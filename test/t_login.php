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

        $removables = ["newuser@hotcrp.com", "scapegoat2@baa.com", "firstchair@hotcrp.com", "cdbonly@hotcrp.com", "resetsid@hotcrp.com", "firstcdb@hotcrp.com", "freshsite@hotcrp.com"];
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

    function test_reset_changes_sid() {
        // A password reset leaves the new password in the session for the
        // sign-in form, so it must change the session ID: others might
        // know the old one [session fixation]
        $email = "resetsid@hotcrp.com";
        $this->conf->invalidate_caches("users");
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("newaccount");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $prep = (new Signin_Page)->mail_user($this->conf, $info);
        xassert(is_string($prep->reset_capability));
        TestQsession::reset();

        // request 1: a visitor obtains anonymous session S
        $qs = TestQsession::start_request(null);
        $qs->open();
        $sid_s = $qs->sid;
        xassert(is_string($sid_s));
        $qs->commit();

        // request 2: a browser carrying S resets the password and gets new
        // ID R, which holds the password for the sign-in form
        $this->conf->invalidate_caches("users");
        $user = Contact::make_email($this->conf, $email);
        $qs = TestQsession::start_request($sid_s);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("resetpassword")->set_qsession($qs);
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword?");
        $qreq->set_req("password2", "newuserpassword?");
        $result = null;
        try {
            $cs = $this->conf->page_components($user, $qreq);
            $cs->callable("Signin_Page")->reset_request($user, $qreq, $cs);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword?"));
        $sid_r = $qs->sid;
        xassert(is_string($sid_r));
        xassert_neqq($sid_r, $sid_s);
        xassert_eqq(TestQsession::$cookies, [$sid_r]);
        $pwr = $qreq->csession("password_reset");
        xassert_eqq($pwr ? $pwr->password : null, "newuserpassword?");
        $qs->commit();

        // request 3: another holder of S does not see the password
        $qs = TestQsession::start_request($sid_s);
        $qreq = TestQreq::get()->set_user(Contact::make($this->conf))->set_page("signin")->set_qsession($qs);
        $qs->maybe_open();
        xassert_eqq($qs->sid, $sid_s);
        xassert(!!$qs->get("deletedat"));
        xassert_eqq($qreq->csession("password_reset"), null);
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, []);

        TestQsession::reset();
    }

    function test_reset_password_validation() {
        $cases = [
            ["ééééé", "Password too short"],
            ["abcdefg\xff", "Invalid UTF-8"],
            [str_repeat("a", 73), "Password too long"],
            [str_repeat("密", 25), "Password too long"],
            ["éééééé", null]
        ];
        $user = Contact::make_email($this->conf, "newuser@hotcrp.com");
        foreach ($cases as $c) {
            $qreq = TestQreq::post(["password" => $c[0], "password2" => $c[0]])
                ->set_user($user)->set_page("resetpassword");
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = new Signin_Page;
            $info = $signinp->reset_request_basic($user, $qreq, $cs, ["ok" => true]);
            if ($c[1] === null) {
                xassert_eqq($info["ok"], true);
                xassert_eqq($info["newpassword"] ?? null, $c[0]);
            } else {
                xassert_eqq($info["ok"], false);
                xassert_str_contains($signinp->ms()->feedback_text_at("password"), $c[1]);
            }
        }
    }

    /** A request for an email with no local account builds its `Contact` out
     * of the POST body. A stored contactdb identity outranks those request
     * fields, just as it would once `Contact::store` ran. */
    function test_forgot_password_uses_cdb_name() {
        if (!$this->cdb) {
            return;
        }
        $email = "cdbonly@hotcrp.com";
        $reqlast = "Tian” <other@example.com>, “Chris";

        // make a contactdb-only, non-placeholder account
        $this->us1->save_user((object) ["email" => $email, "firstName" => "Chris", "lastName" => "Tian"]);
        Dbl::qe($this->cdb, "update ContactInfo set cflags=cflags&~? where email=?",
                Contact::CF_PLACEHOLDER, $email);
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users");
        xassert_eqq($this->conf->fresh_user_by_email($email), null);
        xassert(!$this->conf->fresh_cdb_user_by_email($email)->is_placeholder());

        $qreq = TestQreq::post([
            "email" => $email, "firstName" => "Chris", "lastName" => $reqlast
        ])->set_user(Contact::make($this->conf))->set_page("forgotpassword");
        $info = LoginHelper::forgot_password_info($this->conf, $qreq, false);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert_eqq($u->email, $email);
        xassert_eqq($u->lastName, "Tian");

        // so the reset mail is addressed by the contactdb name
        $prep = $u->prepare_mail($info["mailtemplate"], []);
        $to = (new MimeText("\r\n"))->encode_email_header("To", join(", ", $prep->recipient_texts()));
        xassert_eqq($to, "To: Chris Tian <{$email}>");

        // but a *placeholder* contactdb name yields to the request, so
        // self-registration can still introduce a name
        Dbl::qe($this->cdb, "update ContactInfo set cflags=cflags|? where email=?",
                Contact::CF_PLACEHOLDER, $email);
        $this->conf->invalidate_caches("users");
        $qreq = TestQreq::post([
            "email" => $email, "firstName" => "Chris", "lastName" => $reqlast
        ])->set_user(Contact::make($this->conf))->set_page("newaccount");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        xassert_eqq($info["user"]->lastName, $reqlast);

        // and that name is quoted for the mail header
        $prep = $info["user"]->prepare_mail($info["mailtemplate"], []);
        $to = (new MimeText("\r\n"))->encode_email_header("To", join(", ", $prep->recipient_texts()));
        Unit_Tester::xassert_clean_wire($to);
        xassert_eqq(Unit_Tester::mail_header_skeleton($to), "To: <{$email}>");

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        Dbl::qe($this->cdb, "delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users");
    }

    /** A forgot-password request takes only the email from the request: the
     * reset mail for an account with no name carries none of the request’s
     * text. */
    function test_forgot_password_ignores_request_name() {
        if (!$this->cdb) {
            return;
        }
        $email = "cdbonly@hotcrp.com";
        $reqfirst = "URGENT: call +1-555-0100";

        // make a nameless contactdb-only, non-placeholder account
        $this->us1->save_user((object) ["email" => $email]);
        Dbl::qe($this->cdb, "update ContactInfo set cflags=cflags&~?, firstName='', lastName='' where email=?",
                Contact::CF_PLACEHOLDER, $email);
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users");
        xassert_eqq($this->conf->fresh_user_by_email($email), null);
        $cdbu = $this->conf->fresh_cdb_user_by_email($email);
        xassert(!$cdbu->is_placeholder());
        xassert_eqq($cdbu->firstName, "");

        $qreq = TestQreq::post([
            "email" => $email, "firstName" => $reqfirst, "lastName" => "", "affiliation" => "Phish Inc."
        ])->set_user(Contact::make($this->conf))->set_page("forgotpassword");
        $info = LoginHelper::forgot_password_info($this->conf, $qreq, false);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert_eqq($u->email, $email);
        xassert_eqq($u->firstName, "");
        xassert_eqq($u->lastName, "");
        xassert_eqq($u->affiliation, "");

        $prep = $u->prepare_mail($info["mailtemplate"], []);
        $to = (new MimeText("\r\n"))->encode_email_header("To", join(", ", $prep->recipient_texts()));
        xassert_eqq($to, "To: {$email}");
        xassert(!str_contains($prep->body, $reqfirst));

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        Dbl::qe($this->cdb, "delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users");
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

    function test_reset_request_with_unknown_email() {
        // An email in the reset-code field is not a reset code: a failed
        // forgot-password request reports only its own error
        $email = "nobody-reset@_.com";
        $user = Contact::make($this->conf);
        foreach ([TestQreq::post([]), TestQreq::get([])] as $qreq) {
            $qreq->set_user($user)->set_page("resetpassword")->set_req("resetcap", $email);
            $old_status = Navigation::$http_response_code;
            Navigation::$http_response_code = 200;
            $cs = $this->conf->page_components($user, $qreq);
            $signinp = $cs->callable("Signin_Page");
            $signinp->reset_request($user, $qreq, $cs);
            xassert_eqq(Navigation::$http_response_code, 200);
            xassert_not_str_contains($signinp->ms()->full_feedback_text(), "reset code");
            if ($qreq->is_post()) {
                xassert($signinp->ms()->has_error_at("resetcap"));
                xassert_str_contains($signinp->ms()->full_feedback_text(), $email);
            }
            Navigation::$http_response_code = $old_status;
        }
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

    function test_forgot_urlencoded_email() {
        // A still-urlencoded `email` is decoded before lookup. Whitespace
        // the decoding introduces must not make the lookup miss an existing
        // account, or request-supplied names would replace the stored ones
        // on the password reset mail.
        $this->conf->invalidate_caches("users");
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post([
            "email" => "marina%40poema.ru%20",
            "firstName" => "Eve",
            "lastName" => "” <evil@_.com>, “Marina"
        ])->set_user($user)->set_page("forgotpassword");
        $info = LoginHelper::forgot_password_info($this->conf, $qreq, false);
        xassert_eqq($qreq->email, "marina@poema.ru");
        xassert_eqq($info["ok"], true);
        $u = $info["user"] ?? null;
        xassert(!!$u);
        if (!$u) {
            return;
        }
        xassert($u->contactId > 0);
        xassert_eqq($u->firstName, "Marina");
        xassert_eqq($u->lastName, "Tsvetaeva");
        $to = (new MimeText)->encode_email_header("To", MailPreparation::recipient_address($u));
        xassert_eqq($to, "To: Marina Tsvetaeva <marina@poema.ru>");

        // An account known only to the contact database gets mail too,
        // but again not under a request-supplied name.
        $email = "forgotcdb@_.com";
        if ($this->cdb !== null) {
            Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Anne', lastName='Carson', email=?, affiliation='UCSF', password='', cflags=0", $email);
        }
        $this->conf->invalidate_caches("users");
        $qreq = TestQreq::post([
            "email" => $email,
            "firstName" => "Eve",
            "lastName" => "” <evil@_.com>, “Anne",
            "affiliation" => "NYU"
        ])->set_user($user)->set_page("forgotpassword");
        $info = LoginHelper::forgot_password_info($this->conf, $qreq, false);
        xassert_eqq($info["ok"], $this->cdb !== null);
        $u = $info["user"] ?? null;
        xassert_eqq(!!$u, $this->cdb !== null);
        if ($u) {
            xassert_eqq($u->email, $email);
            xassert_eqq($u->contactId, 0);
            xassert_eqq($u->firstName, "Anne");
            xassert_eqq($u->lastName, "Carson");
            xassert_eqq($u->affiliation, "UCSF");
            $to = (new MimeText)->encode_email_header("To", MailPreparation::recipient_address($u));
            xassert_eqq($to, "To: Anne Carson <{$email}>");
        }
        if ($this->cdb !== null) {
            Dbl::qe($this->cdb, "delete from ContactInfo where email=?", $email);
            $this->conf->invalidate_caches("users");
        }
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

    /** @param string $base */
    private function derive_paper_site($base) {
        $this->conf->set_opt("paperSite", null);
        Navigation::set(NavigationState::make_base($base));
        $this->conf->refresh_settings();
    }

    /** @param bool $bearer */
    private function chair_activates($bearer = false) {
        $u = clone $this->user_chair;
        if ($bearer) {
            $u->set_bearer_authorized();
        }
        $u->activate(TestQreq::get_page("paper", ["p" => 1]), true);
    }

    /** @return string */
    private function chair_visits_home() {
        $rc = RenderCapture::make(TestQreq::get_page("index")->set_user($this->user_chair));
        xassert($rc->ok());
        return $rc->content;
    }

    function test_site_url_for_password_links() {
        $conf = $this->conf;
        $old_site = $conf->opt("paperSite");
        $old_nav = Navigation::get();
        $u = $conf->checked_user_by_email("estrin@usc.edu");

        // a URL from the request does not reach password links
        $this->derive_paper_site("http://attacker.example/test/");
        xassert_eqq($conf->baseurl(), "http://attacker.example/test");
        xassert_eqq($conf->safe_baseurl(), null);
        $prep = $u->prepare_mail("@resetpassword", ["no_error_quit" => true]);
        xassert($prep->has_error());
        xassert_eqq($prep->reset_capability, null);
        // ...and other links stay unexpanded
        $prep = $u->prepare_mail(["subject" => "x", "body" => "{{URL}} {{URL(paper, p=1)}} {{LOGINURL}}"], ["no_error_quit" => true]);
        xassert_eqq(trim($prep->body), "{{URL}} {{URL(paper, p=1)}} {{LOGINURL}}");
        // ...and neither does an imported URL that differs from it
        $sv = (new SettingValues($this->user_chair))->add_json_string('{"site_url": "https://lastyear.example/test"}');
        xassert($sv->execute());
        xassert_eqq($conf->safe_baseurl(), null);
        // ...and previews of password links do not fail
        $j = call_api("mailtext", $this->user_chair, ["email" => "estrin@usc.edu", "template" => "accountinfo"]);
        xassert($j->ok);
        $j = call_api("mailtext", $this->user_chair, ["email" => "estrin@usc.edu", "text" => "", "subject" => "", "body" => "{{PASSWORDLINK}}"]);
        xassert($j->ok);

        // an invalid URL is not recorded, nor is one from a bearer token
        $this->derive_paper_site("http://bad\x7Fhost.example/test/");
        $this->chair_activates();
        xassert_eqq($conf->safe_baseurl(), null);
        $this->derive_paper_site("http://internal.example/test/");
        $this->chair_activates(true);
        xassert_eqq($conf->safe_baseurl(), null);

        // an administrator’s first visit records the site URL
        $this->derive_paper_site("http://real.example/test/");
        $this->chair_activates();
        $this->derive_paper_site("http://attacker.example/test/");
        $this->chair_activates();
        if ($this->cdb) {
            // ...except on contactdb sites, which ignore the setting
            xassert(!SiteURL_SettingParser::site_url_configurable($conf));
            xassert_eqq($conf->safe_baseurl(), null);
            $conf->save_refresh_setting("opt.observedPaperSite", 1, "http://real.example/test");
            xassert_eqq($conf->safe_baseurl(), null);
            xassert(!property_exists((new SettingValues($this->user_chair))->all_jsonv(), "site_url"));
            $sv = SettingValues::make_request($this->user_chair, ["site_url" => "http://other.example/test"]);
            xassert($sv->execute());
            xassert_eqq($conf->safe_baseurl(), null);
        } else {
            xassert_eqq($conf->safe_baseurl(), "http://real.example/test");
            $prep = $u->prepare_mail("@resetpassword", ["no_error_quit" => true]);
            xassert(!$prep->has_error());
            xassert_str_contains($prep->body, "http://real.example/test/resetpassword/hcpw");
            xassert_not_str_contains($prep->body, "attacker.example");

            // administrators can change it on the settings page
            $sv = SettingValues::make_request($this->user_chair, ["site_url" => "ftp://other.example/test", "site_url_force" => "1"]);
            xassert(!$sv->execute());
            $sv = SettingValues::make_request($this->user_chair, ["site_url" => "https://other.example/a\x01b", "site_url_force" => "1"]);
            xassert(!$sv->execute());
            $sv = SettingValues::make_request($this->user_chair, ["site_url" => "https://other.example/test/", "site_url_force" => "1"]);
            xassert($sv->execute());
            xassert_eqq($conf->safe_baseurl(), "https://other.example/test");
            xassert_eqq((new SettingValues($this->user_chair))->all_jsonv()->site_url ?? null, "https://other.example/test");

            // elsewhere, a URL that differs from the one in use is ignored...
            $set_json = function ($v) {
                $sv = (new SettingValues($this->user_chair))->add_json_string(json_encode(["site_url" => $v]));
                xassert($sv->execute());
                return $sv->full_feedback_text();
            };
            xassert_str_contains($set_json("https://lastyear.example/test"), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "https://other.example/test");
            $sv = SettingValues::make_request($this->user_chair, ["site_url" => "https://lastyear.example/test"]);
            xassert($sv->execute());
            xassert_str_contains($sv->full_feedback_text(), "force https://lastyear.example/test");
            xassert_eqq($conf->safe_baseurl(), "https://other.example/test");
            // ...unless forced
            xassert_not_str_contains($set_json("force https://lastyear.example/test"), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "https://lastyear.example/test");
            xassert_not_str_contains($set_json(" FORCE https://other.example/test/"), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "https://other.example/test");
            // the current value and the URL in use need no force
            xassert_not_str_contains($set_json("https://OTHER.example/test"), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "https://OTHER.example/test");
            $this->derive_paper_site("http://real.example/test/");
            xassert_not_str_contains($set_json("http://Real.Example/test/"), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "http://Real.Example/test");
            // `site_url_force` cannot be set in JSON
            $sv = (new SettingValues($this->user_chair))->add_json_string('{"site_url": "https://other.example/test", "site_url_force": true}');
            xassert($sv->execute());
            xassert_str_contains($sv->full_feedback_text(), ["not changed", "ignored"]);
            xassert_eqq($conf->safe_baseurl(), "http://Real.Example/test");
            xassert($set_json("force https://other.example/test") === "");
            xassert_eqq($conf->safe_baseurl(), "https://other.example/test");

            // with a warning if it differs from the current URL
            $sv = SettingValues::make_request($this->user_chair, []);
            $sv->crosscheck();
            xassert_str_contains($sv->full_feedback_text(), "differs from the URL you are using to access the site");
            xassert_str_contains($this->chair_visits_home(), "update the site URL");
        }

        $conf->save_setting("opt.observedPaperSite", null);
        $conf->set_opt("paperSite", $old_site);
        Navigation::set($old_nav);
        $conf->refresh_settings();
    }

    function test_first_user_records_site_url() {
        // a new site has no site URL, but its first user can still sign up
        $conf = $this->conf;
        $old_site = $conf->opt("paperSite");
        $old_nav = Navigation::get();
        $this->derive_paper_site("http://fresh.example/test/");
        $conf->save_setting("setupPhase", 1);
        xassert_eqq($conf->safe_baseurl(), null);
        $user = Contact::make($conf);
        $rc = RenderCapture::make(TestQreq::get_page("newaccount")->set_user($user));
        if ($this->cdb) {
            xassert_str_contains($rc->content, "can’t create accounts");
        } else {
            xassert_str_contains($rc->content, "name=\"email\"");
            $qreq = TestQreq::post(["email" => "freshsite@hotcrp.com"])->set_user($user)->set_page("newaccount");
            $redir = null;
            try {
                (new Signin_Page)->create_request($user, $qreq);
            } catch (Redirection $redir) {
            }
            xassert_str_contains($redir->url ?? "", "http://fresh.example/test/resetpassword/hcpw0");
            xassert_eqq($conf->safe_baseurl(), "http://fresh.example/test");
        }
        $conf->save_setting("setupPhase", null);
        $conf->save_setting("opt.observedPaperSite", null);
        $conf->set_opt("paperSite", $old_site);
        Navigation::set($old_nav);
        $conf->refresh_settings();
    }

    function test_external_login_requires_ascii() {
        // the database collation would match these to chair@_.com
        foreach (["cha\u{ED}r@_.com", "chair\x01@_.com", "chair@_\x7F.com"] as $email) {
            $u = Contact::make_keyed($this->conf, ["email" => $email]);
            $info = LoginHelper::check_external_login($u);
            xassert_eqq($info["ok"], false);
            xassert_eqq($u->contactId, 0);
        }
        $info = LoginHelper::check_external_login($this->conf->checked_user_by_email("chair@_.com"));
        xassert_eqq($info["ok"], true);
    }

    function test_setup_phase_requires_no_cdb() {
        $email = "firstcdb@hotcrp.com";
        $this->conf->save_setting("setupPhase", 1);
        $user = Contact::make($this->conf);
        $qreq = TestQreq::post(["email" => $email])->set_user($user)->set_page("newaccount");
        $redir = null;
        try {
            (new Signin_Page)->create_request($user, $qreq);
        } catch (Redirection $redir) {
        }
        xassert(!!$redir);
        $u = $this->conf->fresh_user_by_email($email);
        if ($this->cdb) {
            // the first user is not special
            xassert_not_str_contains($redir->url, "hcpw");
            xassert(!$u->privChair);
        } else {
            // the first user becomes administrator and sets a password
            xassert_str_contains($redir->url, "resetpassword/hcpw0");
            xassert($u->privChair);
        }
        xassert(!$this->conf->setting("setupPhase"));
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
        // conferences sharing a contactdb have no setup phase
        xassert_eqq($u->privChair, !$this->cdb);
    }
}
