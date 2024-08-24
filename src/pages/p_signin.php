<?php
// pages/p_signin.php -- HotCRP password reset partials
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Signin_Page {
    /** @var ?string */
    public $_reset_tokstr;
    /** @var ?TokenInfo */
    public $_reset_token;
    /** @var ?Contact */
    public $_reset_user;
    /** @var ?associative-array<string,mixed> */
    public $_oauth_hoturl_param;
    /** @var ?MessageSet */
    private $_ms;

    static private function bad_post_error(Contact $user, Qrequest $qreq, $action) {
        $qreq->open_session();
        if ($qreq->post_retry) {
            $user->conf->error_msg($user->conf->_i("session_failed_error"));
        } else {
            $user->conf->warning_msg($user->conf->_i("badpost"));
        }
    }

    /** @return MessageSet */
    function ms() {
        $this->_ms = $this->_ms ?? new MessageSet;
        return $this->_ms;
    }
    /** @param string $field
     * @param string $rest
     * @return string */
    private function control_class($field, $rest = "") {
        return $this->_ms ? $this->_ms->control_class($field, $rest) : $rest;
    }
    /** @param string $field
     * @return string */
    private function feedback_html_at($field) {
        return $this->_ms ? $this->_ms->feedback_html_at($field) : "";
    }
    /** @param string $field
     * @return int */
    private function problem_status_at($field) {
        return $this->_ms ? $this->_ms->problem_status_at($field) : 0;
    }


    // Signin request
    /** @param ComponentSet $cs */
    function signin_request(Contact $user, Qrequest $qreq, $cs) {
        assert($qreq->method() === "POST");
        $conf = $user->conf;
        if ($qreq->cancel) {
            $info = ["ok" => false];
            foreach ($cs->members("signin/request") as $gj) {
                $info = $cs->call_function($gj, $gj->signin_function, $info, $gj);
            }
            $conf->redirect();
        } else if (!$conf->allow_local_signin()) {
            // do nothing
        } else if ($conf->login_type() === "htauth") {
            LoginHelper::check_http_auth($user, $qreq);
        } else if (!$qreq->valid_post()) {
            self::bad_post_error($user, $qreq, "signin");
        } else if (!$user->is_empty()
                   && strcasecmp($qreq->email, $user->email) === 0) {
            $conf->redirect();
        } else if (!$qreq->start) {
            $info = ["ok" => true];
            foreach ($cs->members("signin/request") as $gj) {
                $info = $cs->call_function($gj, $gj->signin_function, $info, $gj);
            }
            if ($info["ok"] || isset($info["redirect"])) {
                $conf->redirect($info["redirect"] ?? "");
            } else if (($code = self::check_password_as_reset_code($user, $qreq))) {
                $conf->redirect_hoturl("resetpassword", ["__PATH__" => $code]);
            } else {
                $info["allow_redirect"] = true;
                LoginHelper::login_error($conf, $qreq->email, $info, $this->ms());
            }
        }
    }

    static function signin_request_basic(Contact $user, Qrequest $qreq, $cs, $info) {
        if (!$info["ok"]) {
            return $info;
        }
        return LoginHelper::login_info($user->conf, $qreq);
    }

    static function signin_request_success(Contact $user, Qrequest $qreq, $cs, $info)  {
        if (!$info["ok"]) {
            if (!empty($info["usec"])) {
                UpdateSession::usec_add_list($qreq, $qreq->email, $info["usec"], 0);
            }
            return $info;
        }
        return LoginHelper::login_complete($info, $qreq);
    }

    /** @param string $token
     * @return ?TokenInfo */
    static private function _find_reset_token(Conf $conf, $token) {
        if ($token) {
            $is_cdb = str_starts_with($token, "hcpw1");
            if (($tok = TokenInfo::find($token, $conf, $is_cdb))
                && $tok->is_active()
                && $tok->capabilityType === TokenInfo::RESETPASSWORD) {
                return $tok;
            }
        }
        return null;
    }

    /** @param Qrequest $qreq
     * @return string|false */
    static function check_password_as_reset_code(Contact $user, $qreq) {
        $pw = trim($qreq->password ?? "");
        if (($cap = self::_find_reset_token($user->conf, $pw))
            && ($capuser = $cap->user())
            && strcasecmp($capuser->email, trim($qreq->email)) === 0) {
            return $pw;
        } else {
            return false;
        }
    }

    /** @param ComponentSet $cs */
    static function print_signin_head(Contact $user, Qrequest $qreq, $cs) {
        $st = $user->conf->saved_messages_status();
        $qreq->print_header("Sign in", "home", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
        $cs->print_on_leave("__footer");
    }

    /** @param string $page
     * @param bool $folded */
    static function print_form_start_for(Qrequest $qreq, $page, $folded = false) {
        $klass = "ui-submit js-signin " . ($folded ? " foldc homegrp" : " signingrp");
        echo Ht::form($qreq->conf()->hoturl($page), ["class" => $klass, "id" => "f-signin"]),
            Ht::hidden("post", $qreq->maybe_post_value());
        if ($qreq->is_post() && !$qreq->valid_token()) {
            echo Ht::hidden("post_retry", "1");
        }
        if ($folded) {
            echo '<div class="signingrp homegrp fx">';
        }
    }

    /** @param ComponentSet $cs */
    static function print_signin_form(Contact $user, Qrequest $qreq, $cs) {
        $conf = $user->conf;
        if (($password_reset = $qreq->csession("password_reset"))) {
            if ($password_reset->time < Conf::$now - 900) {
                $qreq->unset_csession("password_reset");
            } else if (!isset($qreq->email)) {
                $qreq->email = $password_reset->email;
            }
        }

        $folded = $cs->root !== "signin" && !$qreq->signin;
        self::print_form_start_for($qreq, "signin", $folded);
        if ($qreq->redirect) {
            echo Ht::hidden("redirect", $qreq->redirect);
        }
        if ($folded) {
            echo Ht::unstash_script('hotcrp.fold("f-signin",false)');
            $cs->print_members("signin/form");
            echo '</div><div class="fn">',
                Ht::submit("Sign in", ["class" => "btn-success", "tabindex" => 1, "formmethod" => "get"]),
                '</div>';
        } else {
            $cs->print_members("signin/form");
        }
        echo '</form>';
    }

    static function print_signin_form_title(Contact $user, Qrequest $qreq) {
        echo '<h1 class="signin">Sign in</h1>';
    }

    static function print_signin_form_accounts(Contact $user, Qrequest $qreq) {
        if (($su = Contact::session_users($qreq))) {
            $nav = $qreq->navigation();
            $links = [];
            foreach ($su as $i => $email) {
                if ($email !== "") {
                    $usuf = count($su) > 1 ? "u/{$i}/" : "";
                    $links[] = '<a href="' . htmlspecialchars($nav->base_path . $usuf) . '">' . htmlspecialchars($email) . '</a>';
                }
            }
            echo '<p class="is-warning"><span class="warning-mark"></span> ', $user->conf->_("You are already signed in as {:list} on this browser.", $links), '</p>';
        }
    }

    static function print_signin_form_local(Contact $user, Qrequest $qreq, ComponentSet $cs) {
        if ($user->conf->allow_local_signin()) {
            echo '<div class="mt-3">';
            $cs->print_members("__local_signin");
            echo '</div>';
        }
    }

    function print_signin_form_email(Contact $user, Qrequest $qreq) {
        $lt = $user->conf->login_type();
        $email = $qreq->email ?? "";
        echo '<div class="', $this->control_class("email", "f-i fx"), '">',
            Ht::label($lt ? "Username" : "Email", "k-email"),
            $this->feedback_html_at("email"),
            Ht::entry("email", $email, [
                "size" => 36, "id" => "k-email", "class" => "fullw",
                "autocomplete" => "username", "tabindex" => 1,
                "type" => !$lt && !str_ends_with($email, "@_.com") ? "email" : "text",
                "autofocus" => $this->problem_status_at("email")
                    || $email === ""
            ]), '</div>';
    }

    function print_signin_form_password(Contact $user, Qrequest $qreq) {
        $lt = $user->conf->login_type();
        echo '<div class="', $this->control_class("password", "f-i fx"), '">';
        if (!$lt) {
            echo '<div class="float-right"><a href="',
                $user->conf->hoturl("forgotpassword"),
                '" class="n ulh small uic js-href-add-email">Forgot your password?</a></div>';
        }
        $password_reset = $qreq->csession("password_reset");
        echo Ht::label("Password", "k-password"),
            $this->feedback_html_at("password"),
            Ht::password("password",
                $this->problem_status_at("password") !== 1 ? "" : $qreq->password, [
                "size" => 36, "id" => "k-password", "class" => "fullw",
                "autocomplete" => "current-password", "tabindex" => 1,
                "autofocus" => !$this->problem_status_at("email")
                    && ($qreq->email ?? "") !== ""
            ]), '</div>';
        if ($password_reset) {
            echo Ht::unstash_script("\$(function(){\$(\"#k-password\").val(" . json_encode_browser($password_reset->password) . ")})");
        }
    }

    /** @param ComponentSet $cs */
    static function print_signin_form_actions(Contact $user, Qrequest $qreq, $cs) {
        echo '<div class="mt-3">',
            Ht::submit("", "Sign in", ["id" => "k-signin", "class" => "btn-success w-100 flex-grow-1", "tabindex" => 1]),
            '</div>';
    }

    static function print_signin_form_create(Contact $user) {
        if (!$user->conf->login_type() && $user->conf->allow_user_self_register()) {
            echo '<p class="mt-3 mb-0 hint fx">New to the site? <a href="',
                $user->conf->hoturl("newaccount"),
                '" class="uic js-href-add-email">Create an account</a></p>';
        }
    }

    function print_signin_form_oauth(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        if (!$conf->opt("oAuthProviders") && !$conf->opt("oAuthTypes")) {
            return;
        }
        $buttons = [];
        $param = array_merge(["authtype" => null, "post" => $qreq->maybe_post_value()], $this->_oauth_hoturl_param ?? ["redirect" => $qreq->redirect]);
        $top = "";
        foreach ($conf->oauth_providers() as $authdata) {
            if ($authdata->button_html && !($authdata->disabled ?? false)) {
                $param["authtype"] = $authdata->name;
                $buttons[] = Ht::button($authdata->button_html, ["type" => "submit", "formaction" => $conf->hoturl("oauth", $param), "formmethod" => "post", "class" => "{$top}w-100 flex-grow-1"]);
                $top = "mt-2 ";
            }
        }
        if (!empty($buttons)) {
            echo '<div class="mt-5">', join("", $buttons), '</div>';
        }
    }


    // signout
    static function signout_request(Contact $user, Qrequest $qreq) {
        assert($qreq->method() === "POST");
        if ($qreq->cancel) {
            $user->conf->redirect();
        } else if ($qreq->valid_post()) {
            LoginHelper::logout($user, $qreq, true);
            $user->conf->redirect_hoturl("index", "signedout=1");
        } else if ($user->is_empty()) {
            $user->conf->redirect_hoturl("index", "signedout=1");
        } else {
            self::bad_post_error($user, $qreq, "signout");
        }
    }

    /** @param ComponentSet $cs */
    static function print_signout(Contact $user, Qrequest $qreq, $cs) {
        if ($user->is_empty()) {
            $user->conf->error_msg("<5>You are not signed in. " . Ht::link("Return home", $user->conf->hoturl("index")));
            $qreq->print_header("Sign out", "signout", ["action_bar" => "", "body_class" => "body-error"]);
        } else {
            $qreq->print_header("Sign out", "signout", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
            self::print_form_start_for($qreq, "signout");
            echo '<h1 class="signin">Sign out</h1><div class="mb-5">',
                $user->conf->_("Use this page to sign out of the site."),
                '</div><div class="popup-actions">',
                Ht::submit("Sign out", ["class" => "btn-danger", "value" => 1]),
                Ht::submit("cancel", "Cancel", ["class" => "uic js-no-signin", "formnovalidate" => true]),
                '</div></form>';
        }
        $cs->print_on_leave("__footer");
    }


    // newaccount
    /** @param array $info
     * @return HotCRPMailPreparation */
    function mail_user(Conf $conf, $info) {
        $user = $info["user"];
        $prep = $user->prepare_mail($info["mailtemplate"], $info["mailrest"] ?? null);
        $prep->set_self_requested(true);
        if (!$prep->send()) {
            if ($conf->opt("sendEmail")) {
                $conf->feedback_msg(...$prep->message_list());
                $this->ms()->error_at("email");
            } else {
                $conf->error_msg("<0>The system cannot send email at this time. You’ll need help from the site administrator to sign in.");
            }
        } else if (strpos($info["mailtemplate"], "@newaccount") !== false) {
            $conf->success_msg("<0>Sent mail to {$user->email}. When you receive that mail, follow the link to set a password and sign in to the site.");
        } else {
            $conf->success_msg("<0>Sent mail to {$user->email}. When you receive that mail, follow the link to reset your password.");
            if ($prep->reset_capability) {
                $conf->log_for($user, null, "Password link sent " . substr($prep->reset_capability, 0, 12) . "...");
            }
        }
        return $prep;
    }

    private function _print_email_entry($user, $qreq, $k) {
        echo '<div class="', $this->control_class($k, "f-i"), '">',
            '<label for="', $k, '">',
            ($k === "email" ? "Email" : "Email or password reset code"),
            '</label>',
            $this->feedback_html_at("resetcap"),
            $this->feedback_html_at("email"),
            Ht::entry($k, $qreq[$k], [
                "size" => 36, "id" => $k, "class" => "fullw",
                "autocomplete" => $k === "email" ? $k : null,
                "type" => $k === "email" ? $k : "text",
                "autofocus" => true
            ]), '</div>';
    }

    static private function _create_message(Conf $conf) {
        return $conf->_("Enter your email and we’ll create an account and send you instructions for signing in.");
    }
    function create_request(Contact $user, Qrequest $qreq) {
        assert($qreq->method() === "POST");
        $conf = $user->conf;
        if ($qreq->cancel) {
            $conf->redirect();
        } else if ($conf->login_type()
                   || !$conf->allow_user_self_register()) {
            return;
        } else if (!$qreq->valid_post()) {
            self::bad_post_error($user, $qreq, "newaccount");
            return;
        }
        $info = LoginHelper::new_account_info($conf, $qreq);
        if (!$info["ok"]) {
            LoginHelper::login_error($conf, $qreq->email, $info, $this->ms());
            return;
        }
        $prep = $this->mail_user($conf, $info);
        if (!$prep) {
            return;
        }
        if ($prep->sent() && $prep->reset_capability) {
            $this->_reset_tokstr = $prep->reset_capability;
        }
        if ($this->_reset_tokstr && isset($info["firstuser"])) {
            $conf->success_msg("<0>As the first user, you have been assigned system administrator privilege. Use this screen to set a password. All later users will have to sign in normally.");
            $conf->redirect_hoturl("resetpassword", ["__PATH__" => $prep->reset_capability]);
        } else {
            $conf->redirect_hoturl("signin");
        }
    }
    /** @param ComponentSet $cs */
    static function print_newaccount_head(Contact $user, Qrequest $qreq, $cs) {
        $qreq->print_header("New account", "newaccount", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
        $cs->print_on_leave("__footer");
        if (!$user->conf->allow_user_self_register()) {
            $user->conf->error_msg("<0>User self-registration is disabled on this site.");
            echo '<p class="mb-5">', Ht::link("Return home", $user->conf->hoturl("index")), '</p>';
            return false;
        }
    }
    /** @param ComponentSet $cs */
    static function print_newaccount_body(Contact $user, Qrequest $qreq, $cs) {
        self::print_form_start_for($qreq, "newaccount");
        $cs->print_members("newaccount/form");
        echo '</form>';
        Ht::stash_script("hotcrp.focus_within(\$(\"#f-signin\"));window.scroll(0,0)");
    }
    static function print_newaccount_form_title() {
        echo '<h1 class="signin">Create account</h1>';
    }
    static function print_newaccount_form_description(Contact $user) {
        $m = $user->conf->_("Enter your email and we’ll create an account and send you instructions for signing in.");
        if ($m) {
            echo '<p class="mb-5">', $m, '</p>';
        }
    }
    function print_newaccount_form_email(Contact $user, Qrequest $qreq) {
        $this->_print_email_entry($user, $qreq, "email");
    }
    static function print_newaccount_form_actions(Contact $user, Qrequest $qreq) {
        echo '<div class="popup-actions">',
            Ht::submit("Create account", ["class" => "btn-primary"]),
            // Ht::submit("cancel", "Cancel", ["class" => "uic js-no-signin", "formnovalidate" => true]),
            '</div>';
    }


    // Forgot password request
    static function forgot_externallogin_message(Contact $user) {
        $user->conf->error_msg("<0>Password reset links aren’t used for this site. Contact your system administrator if you’ve forgotten your password.");
        echo '<p class="mb-5">', Ht::link("Return home", $user->conf->hoturl("index")), '</p>';
        return false;
    }
    function forgot_request(Contact $user, Qrequest $qreq) {
        assert($qreq->method() === "POST");
        if ($qreq->cancel) {
            $user->conf->redirect();
        } else if ($qreq->valid_post()) {
            $info = LoginHelper::forgot_password_info($user->conf, $qreq, false);
            if ($info["ok"]) {
                $this->mail_user($user->conf, $info);
                $user->conf->redirect($info["redirect"] ?? $qreq->annex("redirect"));
            } else {
                LoginHelper::login_error($user->conf, $qreq->email, $info, $this->ms());
            }
        } else if ($qreq->is_post()) {
            self::bad_post_error($user, $qreq, "forgotpassword");
        }
    }
    static function print_forgot_head(Contact $user, Qrequest $qreq, $cs) {
        $qreq->print_header("Forgot password", "resetpassword", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
        $cs->print_on_leave("__footer");
        if ($user->conf->login_type()) {
            return $cs->print("forgotpassword/__externallogin");
        }
    }
    static function print_forgot_body(Contact $user, Qrequest $qreq, $cs) {
        self::print_form_start_for($qreq, "forgotpassword");
        $cs->print_members("forgotpassword/form");
        echo '</form>';
        Ht::stash_script("hotcrp.focus_within(\$(\"#f-signin\"));window.scroll(0,0)");
    }
    static function print_forgot_form_title() {
        echo '<h1 class="signin">Forgot password</h1>';
    }
    static function print_forgot_form_description(Contact $user, Qrequest $qreq, $cs) {
        echo '<p class="mb-5">Enter your email and we’ll send you a link to reset your password.';
        if ($cs->root === "resetpassword") {
            echo ' Or enter a password reset code if you have one.';
        }
        echo '</p>';
    }
    function print_forgot_form_email(Contact $user, Qrequest $qreq, $cs) {
        $this->_print_email_entry($user, $qreq,
            $cs->root === "resetpassword" ? "resetcap" : "email");
    }
    function print_forgot_form_actions() {
        echo '<div class="popup-actions">',
            Ht::submit("Reset password", ["class" => $this->_reset_user ? "btn-success" : "btn-primary"]),
            // Ht::submit("cancel", "Cancel", ["class" => "uic js-no-signin", "formnovalidate" => true]),
            '</div>';
    }


    // Password reset
    function reset_request(Contact $user, Qrequest $qreq, $cs) {
        $conf = $user->conf;
        if ($conf->login_type()) {
            return;
        }

        // exit on cancel
        if ($qreq->cancel) {
            $info = ["ok" => false];
            foreach ($cs->members("resetpassword/request") as $gj) {
                $info = $cs->call_function($gj, $gj->signin_function, $info, $gj);
            }
            $conf->redirect();
            return;
        }

        // derive `resetcap` parameter, maybe from URL
        if ($qreq->resetcap === null
            && preg_match('/\A\/(hcpw[01][a-zA-Z]+)(?:\/|\z)/', $qreq->path(), $m)) {
            $qreq->resetcap = $m[1];
        }

        // find token string
        $resetcap = trim((string) $qreq->resetcap);
        if (preg_match('/\A\/?(hcpw[01][a-zA-Z]+)\/?\z/', $resetcap, $m)) {
            $this->_reset_tokstr = $m[1];
        } else if (strpos($resetcap, "@") !== false) {
            if ($qreq->valid_post()) {
                $nqreq = new Qrequest("POST", ["email" => $resetcap]);
                $nqreq->approve_token();
                $nqreq->set_annex("redirect", $user->conf->hoturl_raw("resetpassword", null, Conf::HOTURL_SERVERREL));
                $this->forgot_request($user, $nqreq); // may redirect
                if ($this->problem_status_at("email")) {
                    $this->ms()->error_at("resetcap");
                }
            }
        }
        if (!$this->_reset_tokstr) {
            return;
        }

        // look up token
        $token = self::_find_reset_token($conf, $this->_reset_tokstr);
        if (!$token) {
            $this->ms()->error_at("resetcap", "Unknown or expired password reset code. Please check that you entered the code correctly.");
            return;
        }
        if (!$token->user()) {
            $this->ms()->error_at("resetcap", "This password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.");
            return;
        }
        $this->_reset_token = $token;
        $this->_reset_user = $token->user();
        $qreq->open_session();

        // ensure POST
        if (!$qreq->is_post()) {
            return;
        }
        if (!$qreq->valid_post()) {
            self::bad_post_error($user, $qreq, "resetpassword");
            return;
        }

        // process request
        $info = ["ok" => true, "user" => $this->_reset_user];
        foreach ($cs->members("resetpassword/request") as $gj) {
            $info = $cs->call_function($gj, $gj->signin_function, $info, $gj);
        }
        if (isset($info["redirect"])) {
            $conf->redirect($info["redirect"]);
        }
    }
    function reset_request_basic(Contact $user, Qrequest $qreq, $cs, $info) {
        if (!$info["ok"]) {
            return $info;
        }
        $p1 = (string) $qreq->password;
        $p2 = (string) $qreq->password2;
        if ($p1 === "") {
            if ($p2 !== "" || $qreq->autopassword) {
                $this->ms()->error_at("password", "Password required.");
            }
            $info["ok"] = false;
        } else if (trim($p1) !== $p1) {
            $this->ms()->error_at("password", "Passwords cannot begin or end with spaces.");
            $this->ms()->error_at("password2");
            $info["ok"] = false;
        } else if (strlen($p1) <= 5) {
            $this->ms()->error_at("password", "Passwords must be at least six characters long.");
            $this->ms()->error_at("password2");
            $info["ok"] = false;
        } else if (!Contact::valid_password($p1)) {
            $this->ms()->error_at("password", "Invalid password.");
            $this->ms()->error_at("password2");
            $info["ok"] = false;
        } else if ($p1 !== $p2) {
            $this->ms()->error_at("password", "The passwords you entered did not match.");
            $this->ms()->error_at("password2");
            $info["ok"] = false;
        } else {
            $info["newpassword"] = $p1;
        }
        return $info;
    }
    function reset_request_success(Contact $user, Qrequest $qreq, $cs, $info) {
        if (!$info["ok"] || !isset($info["newpassword"])) {
            return $info;
        }
        // actually reset password
        $accthere = $this->_reset_user->ensure_account_here();
        $accthere->change_password($info["newpassword"]);
        $accthere->log_activity("Password reset via " . substr($this->_reset_tokstr, 0, 12) . "...");
        $user->conf->success_msg("<0>Password changed. Use the new password to sign in below.");
        $this->_reset_token->delete();
        $qreq->set_csession("password_reset", (object) [
            "time" => Conf::$now,
            "email" => $this->_reset_user->email,
            "password" => $info["newpassword"]
        ]);
        $info["redirect"] = $info["redirect"] ?? $user->conf->hoturl_raw("signin");
        return $info;
    }
    static function print_reset_head(Contact $user, Qrequest $qreq, $cs) {
        $qreq->print_header("Reset password", "resetpassword", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
        $cs->print_on_leave("__footer");
        if ($user->conf->login_type()) {
            return $cs->print("forgotpassword/__externallogin");
        }
    }
    function print_reset_body(Contact $user, Qrequest $qreq, $cs) {
        self::print_form_start_for($qreq, "resetpassword");
        if ($this->_reset_user) {
            echo Ht::hidden("resetcap", $this->_reset_tokstr);
            $cs->print_members("resetpassword/form");
        } else {
            $cs->print_members("forgotpassword/form");
        }
        echo '</form>';
        Ht::stash_script("hotcrp.focus_within(\$(\"#f-signin\"));window.scroll(0,0)");
    }
    static function print_reset_form_title() {
        echo '<h1 class="signin">Reset password</h1>';
    }
    static function print_reset_form_description() {
        echo '<p class="mb-5">Use this form to set a new password. You may want to use the random password we’ve chosen.</p>';
    }
    function print_reset_form_email() {
        echo '<div class="f-i"><label>Email</label>', htmlspecialchars($this->_reset_user->email), '</div>',
            Ht::entry("email", $this->_reset_user->email, ["class" => "hidden", "autocomplete" => "username"]);
    }
    static function print_reset_form_autopassword(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->autopassword)
            || trim($qreq->autopassword) !== $qreq->autopassword
            || strlen($qreq->autopassword) < 16
            || !preg_match('/\A[-0-9A-Za-z@_+=]*\z/', $qreq->autopassword)) {
            $qreq->autopassword = hotcrp_random_password();
        }
        echo '<div class="f-i"><label for="autopassword">Suggested strong password</label>',
            Ht::entry("autopassword", $qreq->autopassword, ["class" => "fullw", "size" => 36, "id" => "autopassword", "readonly" => true]),
            '</div>';
    }
    function print_reset_form_password() {
        echo '<div class="', $this->control_class("password", "f-i"), '">',
            '<label for="password">New password</label>',
            $this->feedback_html_at("password"),
            Ht::password("password", "", ["class" => "fullw", "size" => 36, "id" => "password", "autocomplete" => "new-password", "autofocus" => true]),
            '</div>',

            '<div class="', $this->control_class("password2", "f-i"), '">',
            '<label for="password2">Repeat new password</label>',
            $this->feedback_html_at("password2"),
            Ht::password("password2", "", ["class" => "fullw", "size" => 36, "id" => "password2", "autocomplete" => "new-password"]),
            '</div>';
    }
}
