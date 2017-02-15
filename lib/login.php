<?php
// login.php -- HotCRP login helpers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class LoginHelper {

    static function logout($explicit) {
        global $Me, $Conf;
        if (!$Me->is_empty() && $explicit && !$Conf->opt("httpAuthLogin"))
            $Conf->confirmMsg("You have been signed out. Thanks for using the system.");
        unset($_SESSION["trueuser"]);
        unset($_SESSION["last_actas"]);
        // clear all conference session info, except maybe capabilities
        $capabilities = $Conf->session("capabilities");
        unset($_SESSION[$Conf->dsn]);
        if (!$explicit && $capabilities)
            $Conf->save_session("capabilities", $capabilities);
        if ($explicit) {
            unset($_SESSION["login_bounce"]);
            if ($Conf->opt("httpAuthLogin")) {
                $_SESSION["reauth"] = true;
                go("");
            }
        }
        $Me = new Contact;
        $Me = $Me->activate();
    }

    static function check_http_auth() {
        global $Conf, $Me;
        assert($Conf->opt("httpAuthLogin"));

        // if user signed out of HTTP authentication, send a reauth request
        if (isset($_SESSION["reauth"])) {
            unset($_SESSION["reauth"]);
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($Conf->opt("httpAuthLogin")))
                header("WWW-Authenticate: " . $Conf->opt("httpAuthLogin"));
            else
                header("WWW-Authenticate: Basic realm=\"HotCRP\"");
            exit;
        }

        // if user is still valid, OK
        if ($Me->has_database_account())
            return;

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            $Conf->header("Error", "home", actionBar());
            Conf::msg_error("This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.");
            $Conf->footer();
            exit;
        }
        $_REQUEST["email"] = $_SERVER["REMOTE_USER"];
        if (validate_email($_REQUEST["email"]))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"];
        else if (($x = $Conf->opt("defaultEmailDomain"))
                 && validate_email($_REQUEST["email"] . "@" . $x))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"] . "@" . $x;
        $_REQUEST["action"] = "login";
        if (!self::check_login()) {
            $Conf->footer();
            exit;
        }
    }

    static function check_login() {
        global $Me;
        if (($user = self::login()))
            $Me = $user->activate();
    }

    static private function login() {
        global $Conf, $Now, $email_class, $password_class;
        $external_login = $Conf->external_login();

        // In all cases, we need to look up the account information
        // to determine if the user is registered
        if (!isset($_REQUEST["email"])
            || ($_REQUEST["email"] = trim($_REQUEST["email"])) == "") {
            $email_class = " error";
            if ($Conf->opt("ldapLogin"))
                return Conf::msg_error("Enter your LDAP username.");
            else
                return Conf::msg_error("Enter your email address.");
        }

        // Check for the cookie
        if (isset($_SESSION["testsession"]))
            /* Session cookie set */;
        else if (!isset($_REQUEST["testsession"])) {
            // set a cookie to test that their browser supports cookies
            $_SESSION["testsession"] = true;
            $url = "testsession=1";
            foreach (array("email", "password", "action", "go", "signin") as $a)
                if (isset($_REQUEST[$a]))
                    $url .= "&$a=" . urlencode($_REQUEST[$a]);
            Navigation::redirect("?" . $url);
        } else
            return Conf::msg_error("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

        // do LDAP login before validation, since we might create an account
        if ($Conf->opt("ldapLogin")) {
            $_REQUEST["action"] = "login";
            if (!self::ldap_login())
                return null;
        }

        // look up user in our database
        if (strpos($_REQUEST["email"], "@") === false)
            self::unquote_double_quoted_request();
        $user = $Conf->user_by_email($_REQUEST["email"]);

        // look up or create user in contact database
        $cdb_user = null;
        if (opt("contactdb_dsn")) {
            if ($user)
                $cdb_user = $user->contactdb_user();
            else
                $cdb_user = Contact::contactdb_find_by_email($_REQUEST["email"]);
        }

        // create account if requested
        if ($_REQUEST["action"] == "new") {
            if (!($user = self::create_account($user, $cdb_user)))
                return null;
            $_REQUEST["password"] = $user->plaintext_password();
        }

        // auto-create account if external login
        if (!$user && $external_login) {
            $reg = Contact::safe_registration($_REQUEST);
            $reg->no_validate_email = true;
            if (!($user = Contact::create($Conf, $reg)))
                return Conf::msg_error($Conf->db_error_html(true, "while adding your account"));
            if ($Conf->setting("setupPhase", false))
                return self::first_user($user, $msg);
        }

        // if no user found, then fail
        if (!$user && (!$cdb_user || !$cdb_user->allow_contactdb_password())) {
            $email_class = " error";
            return Conf::msg_error("No account for " . htmlspecialchars($_REQUEST["email"]) . ". Did you enter the correct email address?");
        }

        // if user disabled, then fail
        if ($user && $user->disabled)
            return Conf::msg_error("Your account is disabled. Contact the site administrator for more information.");

        // maybe reset password
        $xuser = $user ? : $cdb_user;
        if ($_REQUEST["action"] == "forgot") {
            $worked = $xuser->sendAccountInfo("forgot", true);
            if ($worked == "@resetpassword")
                $Conf->confirmMsg("A password reset link has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ". When you receive that email, follow its instructions to create a new password.");
            else if ($worked) {
                $Conf->confirmMsg("Your password has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ".  When you receive that email, return here to sign in.");
                $Conf->log("Sent password", $xuser);
            }
            return null;
        }

        // check password
        if (!$external_login) {
            if (($password = trim(req_s("password"))) === "") {
                $password_class = " error";
                return Conf::msg_error("Enter your password. If you’ve forgotten it, enter your email address and use the “I forgot my password” option.");
            }

            if (!$xuser->check_password($password)) {
                $password_class = " error";
                return Conf::msg_error("That password doesn’t match. If you’ve forgotten your password, enter your email address and use the “I forgot my password” option.");
            }
        }

        // mark activity
        $xuser->mark_login();

        // activate and redirect
        $user = $xuser->activate();
        unset($_SESSION["testsession"]);
        $_SESSION["trueuser"] = (object) array("email" => $user->email);
        $Conf->save_session("freshlogin", true);
        $Conf->save_session("password_reset", null);

        if (isset($_REQUEST["go"]))
            $where = $_REQUEST["go"];
        else if (isset($_SESSION["login_bounce"])
                 && $_SESSION["login_bounce"][0] == $Conf->dsn)
            $where = $_SESSION["login_bounce"][1];
        else
            $where = hoturl("index");
        go($where);
        exit;
    }

    static private function ldap_login() {
        global $Conf, $ConfSitePATH;
        // check for bogus configurations
        if (!function_exists("ldap_connect") || !function_exists("ldap_bind"))
            return Conf::msg_error("Internal error: <code>\$Opt[\"ldapLogin\"]</code> is set, but this PHP installation doesn’t support LDAP. Logins will fail until this error is fixed.");

        // the body is elsewhere because we need LDAP constants, which might[?]
        // cause errors absent LDAP support
        require_once("$ConfSitePATH/lib/ldaplogin.php");
        return ldapLoginAction();
    }

    static private function unquote_double_quoted_request() {
        global $Conf;
        if (strpos($_REQUEST["email"], "@") !== false
            || strpos($_REQUEST["email"], "%40") === false)
            return false;
        // error_log("double-encoded request: " . json_encode($_REQUEST));
        foreach ($_REQUEST as $k => &$v)
            $v = rawurldecode($v);
        return true;
    }

    static private function create_account($user, $cdb_user) {
        global $Conf, $email_class;

        // check for errors
        if ($user && $user->has_database_account() && $user->activity_at > 0) {
            $email_class = " error";
            return Conf::msg_error("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ". To retrieve your password, select “I forgot my password.”");
        } else if ($cdb_user && $cdb_user->allow_contactdb_password()
                   && $cdb_user->activity_at > 0) {
            $desc = opt("contactdb_description") ? : "HotCRP";
            $email_class = " error";
            return Conf::msg_error("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . " on $desc. Sign in using your $desc password or select “I forgot my password.”");
        } else if (!validate_email($_REQUEST["email"])) {
            $email_class = " error";
            return Conf::msg_error("“" . htmlspecialchars($_REQUEST["email"]) . "” is not a valid email address.");
        }

        // create database account
        if (!$user || !$user->has_database_account()) {
            if (!($user = Contact::create($Conf, Contact::safe_registration($_REQUEST))))
                return Conf::msg_error($Conf->db_error_html(true, "while adding your account"));
        }

        $user->sendAccountInfo("create", true);
        $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".";

        // handle setup phase
        if ($Conf->setting("setupPhase", false))
            return self::first_user($user, $msg);

        if (Mailer::allow_send($user->email))
            $msg .= " A password has been emailed to you.  Return here when you receive it to complete the registration process.  If you don’t receive the email, check your spam folders and verify that you entered the correct address.";
        else {
            if (opt("sendEmail"))
                $msg .= " The email address you provided seems invalid.";
            else
                $msg .= " The conference system is not set up to mail passwords at this time.";
            $msg .= " Although an account was created for you, you need help to retrieve your password. Contact " . Text::user_html($Conf->site_contact()) . ".";
        }
        if (isset($_REQUEST["password"]) && trim($_REQUEST["password"]) != "")
            $msg .= " Note that the password you supplied on the login screen was ignored.";
        $Conf->confirmMsg($msg);
        return null;
    }

    static private function first_user($user, $msg) {
        global $Conf;
        $msg .= " As the first user, you have been automatically signed in and assigned system administrator privilege.";
        if (!$Conf->external_login())
            $msg .= " Your password is “<samp>" . htmlspecialchars($user->plaintext_password()) . "</samp>”. All later users will have to sign in normally.";
        $user->save_roles(Contact::ROLE_ADMIN, null);
        $Conf->save_setting("setupPhase", null);
        $Conf->confirmMsg($msg);
        return $user;
    }

}
