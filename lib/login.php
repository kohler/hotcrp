<?php
// login.php -- HotCRP login helpers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class LoginHelper {
    static function logout(Contact $user, $explicit) {
        if (!$user->is_empty() && $explicit && !$user->conf->opt("httpAuthLogin"))
            $user->conf->confirmMsg("You have been signed out. Thanks for using the system.");
        if (isset($_SESSION))
            unset($_SESSION["trueuser"], $_SESSION["last_actas"],
                  $_SESSION["updatecheck"], $_SESSION["sg"]);
        // clear all conference session info, except maybe capabilities
        $capabilities = $user->conf->session("capabilities");
        if (isset($_SESSION))
            unset($_SESSION[$user->conf->dsn]);
        if (!$explicit && $capabilities)
            $user->conf->save_session("capabilities", $capabilities);
        if ($explicit) {
            ensure_session();
            unset($_SESSION["login_bounce"]);
            if ($user->conf->opt("httpAuthLogin")) {
                $_SESSION["reauth"] = true;
                go("");
            }
        }
        $user = new Contact(null, $user->conf);
        return $user->activate(null);
    }

    static function check_http_auth(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        assert($conf->opt("httpAuthLogin") !== null);

        // if user signed out of HTTP authentication, send a reauth request
        if (isset($_SESSION["reauth"])) {
            unset($_SESSION["reauth"]);
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($conf->opt("httpAuthLogin")))
                header("WWW-Authenticate: " . $conf->opt("httpAuthLogin"));
            else
                header("WWW-Authenticate: Basic realm=\"HotCRP\"");
            exit;
        }

        // if user is still valid, OK
        if ($Me->has_database_account())
            return;

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            $conf->header("Error", "home");
            Conf::msg_error("This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.");
            $conf->footer();
            exit;
        }
        $qreq->email = $_SERVER["REMOTE_USER"];
        if (validate_email($qreq->email))
            $qreq->preferredEmail = $qreq->email;
        else if (($x = $conf->opt("defaultEmailDomain"))
                 && validate_email($qreq->email . "@" . $x))
            $qreq->preferredEmail = $qreq->email . "@" . $x;
        $qreq->action = "login";
        self::login_redirect($conf, $qreq); // redirect on success

        $conf->header("Error", "home");
        Conf::msg_error("This site is using HTTP authentication to manage its users, and you have provided incorrect authentication data.");
        $conf->footer();
        exit;
    }

    static function login_redirect(Conf $conf, Qrequest $qreq) {
        global $Now;
        $external_login = $conf->external_login();

        // In all cases, we need to look up the account information
        // to determine if the user is registered
        if (!isset($qreq->email)
            || ($qreq->email = trim($qreq->email)) === "") {
            Ht::error_at("email");
            if ($conf->opt("ldapLogin"))
                return Conf::msg_error("Enter your LDAP username.");
            else
                return Conf::msg_error("Enter your email address.");
        }

        // do LDAP login before validation, since we might create an account
        if ($conf->opt("ldapLogin")) {
            $qreq->action = "login";
            if (!self::ldap_login($qreq))
                return null;
        }

        // look up user in our database
        if (strpos($qreq->email, "@") === false)
            self::unquote_double_quoted_request($qreq);
        $user = $conf->user_by_email($qreq->email);

        // look up or create user in contact database
        $cdb_user = null;
        if ($conf->opt("contactdb_dsn")) {
            if ($user)
                $cdb_user = $user->contactdb_user();
            else
                $cdb_user = $conf->contactdb_user_by_email($qreq->email);
        }

        // create account if requested
        if ($qreq->action === "new") {
            $user = self::create_account($conf, $qreq, $user, $cdb_user);
            if (!$user)
                return null;
            $qreq->password = $user->plaintext_password();
        }

        // auto-create account if external login
        if (!$user && $external_login) {
            $user = Contact::create($conf, null, $qreq->make_array(), Contact::SAVE_ANY_EMAIL);
            if (!$user)
                return Conf::msg_error($conf->db_error_html(true, "while adding your account"));
            if ($conf->setting("setupPhase", false))
                self::first_user($user, $msg);
        }

        // if no user found, then fail
        if (!$user && (!$cdb_user || !$cdb_user->allow_contactdb_password())) {
            Ht::error_at("email");
            return Conf::msg_error("No account for " . htmlspecialchars($qreq->email) . ". Did you enter the correct email address?");
        }

        // if user disabled, then fail
        if ($user && $user->disabled)
            return Conf::msg_error("Your account is disabled. Contact the site administrator for more information.");

        // maybe reset password
        $xuser = $user ? : $cdb_user;
        if ($qreq->action === "forgot") {
            $worked = $xuser->sendAccountInfo("forgot", true);
            if ($worked === "@resetpassword") {
                $conf->confirmMsg("A password reset link has been emailed to " . htmlspecialchars($qreq->email) . ". When you receive that email, follow its instructions to create a new password.");
            } else if ($worked) {
                $conf->confirmMsg("Your password has been emailed to " . htmlspecialchars($qreq->email) . ". When you receive that email, return here to sign in.");
                $conf->log_for($xuser, null, "Sent password");
            }
            return null;
        }

        // check password
        if (!$external_login) {
            $password = trim((string) $qreq->password);
            if ($password === "") {
                Ht::error_at("password");
                return Conf::msg_error("Password missing.");
            }

            if (!$xuser->check_password($password)) {
                Ht::error_at("password");
                if ($xuser->password_is_reset())
                    return Conf::msg_error("Your previous password has been disabled. Use “Forgot your password?” to create a new password.");
                else
                    return Conf::msg_error("Incorrect password.");
            }
        }

        // mark activity
        $xuser->mark_login();

        // activate and redirect
        ensure_session();
        $_SESSION["trueuser"] = (object) array("email" => $xuser->email);
        $_SESSION["testsession"] = true;
        $user = $xuser->activate($qreq);
        $conf->save_session("freshlogin", true);
        $conf->save_session("password_reset", null);

        go($conf->hoturl("index", ["go" => $qreq->go, "postlogin" => 1]));
        exit;
    }

    static function check_postlogin(Contact $user, Qrequest $qreq) {
        // Check for the cookie
        if (!isset($_SESSION["testsession"]) || !$_SESSION["testsession"]) {
            return Conf::msg_error("You appear to have disabled cookies in your browser. This site requires cookies to function.");
        }
        unset($_SESSION["testsession"]);

        // Go places
        if (isset($qreq->go))
            $where = $qreq->go;
        else if (isset($_SESSION["login_bounce"])
                 && $_SESSION["login_bounce"][0] == $user->conf->dsn)
            $where = $_SESSION["login_bounce"][1];
        else
            $where = hoturl("index");
        go($where);
        exit;
    }

    static private function ldap_login($qreq) {
        global $ConfSitePATH;
        // check for bogus configurations
        if (!function_exists("ldap_connect") || !function_exists("ldap_bind"))
            return Conf::msg_error("Internal error: <code>\$Opt[\"ldapLogin\"]</code> is set, but this PHP installation doesn’t support LDAP. Logins will fail until this error is fixed.");

        // the body is elsewhere because we need LDAP constants, which might[?]
        // cause errors absent LDAP support
        require_once("$ConfSitePATH/lib/ldaplogin.php");
        return ldapLoginAction($qreq);
    }

    static private function unquote_double_quoted_request($qreq) {
        if (strpos($qreq->email, "@") !== false
            || strpos($qreq->email, "%40") === false)
            return false;
        // error_log("double-encoded request: " . json_encode($qreq));
        foreach ($qreq->keys() as $k)
            $qreq[$k] = rawurldecode($qreq[$k]);
        return true;
    }

    static private function create_account($conf, $qreq, $user, $cdb_user) {
        // check for errors
        if ($user && $user->has_database_account() && $user->activity_at > 0) {
            Ht::error_at("email");
            return Conf::msg_error("An account already exists for " . htmlspecialchars($qreq->email) . ". Enter your password or select “Forgot your password?” to reset it.");
        } else if ($cdb_user
                   && $cdb_user->allow_contactdb_password()
                   && $cdb_user->password_used()) {
            $desc = $conf->opt("contactdb_description") ? : "HotCRP";
            Ht::error_at("email");
            return Conf::msg_error("An account already exists for " . htmlspecialchars($qreq->email) . " on $desc. Sign in using your $desc password or select “Forgot your password?” to reset it.");
        } else if (!validate_email($qreq->email)) {
            Ht::error_at("email");
            return Conf::msg_error("“" . htmlspecialchars($qreq->email) . "” is not a valid email address.");
        }

        // create database account
        if (!$user || !$user->has_database_account()) {
            if (!($user = Contact::create($conf, null, $qreq->make_array())))
                return Conf::msg_error($conf->db_error_html(true, "while adding your account"));
        }

        $user->sendAccountInfo("create", true);
        $msg = "Successfully created an account for " . htmlspecialchars($qreq->email) . ".";

        // handle setup phase
        if ($conf->setting("setupPhase", false)) {
            self::first_user($user, $msg);
            return $user;
        }

        if (Mailer::allow_send($user->email))
            $msg .= " A password has been emailed to you. Return here when you receive it to complete the registration process. If you don’t receive the email, check your spam folders and verify that you entered the correct address.";
        else {
            if ($conf->opt("sendEmail"))
                $msg .= " The email address you provided seems invalid.";
            else
                $msg .= " The conference system is not set up to mail passwords at this time.";
            $msg .= " Although an account was created for you, you need help to retrieve your password. Contact " . Text::user_html($conf->site_contact()) . ".";
        }
        if (isset($qreq->password) && trim($qreq->password) !== "")
            $msg .= " The password you supplied on the login screen was ignored.";
        $conf->confirmMsg($msg);
        return null;
    }

    static private function first_user($user, $msg) {
        $msg .= " As the first user, you have been automatically signed in and assigned system administrator privilege.";
        if (!$user->conf->external_login())
            $msg .= " Your password is “<samp>" . htmlspecialchars($user->plaintext_password()) . "</samp>”. All later users will have to sign in normally.";
        $user->save_roles(Contact::ROLE_ADMIN, null);
        $user->conf->save_setting("setupPhase", null);
        $user->conf->confirmMsg($msg);
    }
}
