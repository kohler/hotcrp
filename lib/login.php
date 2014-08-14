<?php
// login.php -- HotCRP login helpers
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class LoginHelper {

    static function logout() {
        global $Me, $Conf, $Opt;
        if (!$Me->is_empty() && isset($_REQUEST["signout"])
            && !isset($Opt["httpAuthLogin"]))
            $Conf->confirmMsg("You have been signed out. Thanks for using the system.");
        $Me = new Contact;
        unset($_SESSION["trueuser"]);
        unset($_SESSION["last_actas"]);
        unset($_SESSION["login_bounce"]);
        // clear all conference session info, except maybe capabilities
        $capabilities = $Conf->session("capabilities");
        unset($_SESSION[$Conf->dsn]);
        if (!isset($_REQUEST["signout"]) && $capabilities)
            $Conf->save_session("capabilities", $capabilities);
        // backwards compatibility
        unset($_SESSION["user"]);
        if (isset($_REQUEST["signout"])) {
            unset($_SESSION["login_bounce"]);
            if (isset($Opt["httpAuthLogin"])) {
                $_SESSION["reauth"] = true;
                go("");
            }
        }
    }

    static function http_auth_check() {
        global $Conf, $Opt, $Me;
        assert(isset($Opt["httpAuthLogin"]));

        // if user signed out of HTTP authentication, send a reauth request
        if (isset($_SESSION["reauth"])) {
            unset($_SESSION["reauth"]);
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($Opt["httpAuthLogin"]))
                header("WWW-Authenticate: " . $Opt["httpAuthLogin"]);
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
            $Conf->errorMsg("This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.");
            $Conf->footer();
            exit;
        }
        $_REQUEST["email"] = $_SERVER["REMOTE_USER"];
        if (validate_email($_REQUEST["email"]))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"];
        else if (isset($Opt["defaultEmailDomain"])
                 && validate_email($_REQUEST["email"] . "@" . $Opt["defaultEmailDomain"]))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"] . "@" . $Opt["defaultEmailDomain"];
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
        global $Conf, $Now, $Opt, $email_class, $password_class;
        $external_login = isset($Opt["ldapLogin"]) || isset($Opt["httpAuthLogin"]);

        // In all cases, we need to look up the account information
        // to determine if the user is registered
        if (!isset($_REQUEST["email"])
            || ($_REQUEST["email"] = trim($_REQUEST["email"])) == "") {
            $email_class = " error";
            if (isset($Opt["ldapLogin"]))
                return $Conf->errorMsg("Enter your LDAP username.");
            else
                return $Conf->errorMsg("Enter your email address.");
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
            go("?" . $url);
        } else
            return $Conf->errorMsg("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

        // do LDAP login before validation, since we might create an account
        if (isset($Opt["ldapLogin"])) {
            $_REQUEST["action"] = "login";
            if (!self::ldap_login())
                return null;
        }

        // look up user in our database
        if (strpos($_REQUEST["email"], "@") === false)
            self::unquote_double_quoted_request();
        $user = Contact::find_by_email($_REQUEST["email"]);

        // look up user in contact database
        $cdb_user = null;
        if (@$Opt["contactdb_dsn"])
            $cdb_user = Contact::contactdb_find_by_email($_REQUEST["email"]);

        // create account if requested
        if ($_REQUEST["action"] == "new") {
            if (!($user = self::create_account($user, $cdb_user)))
                return null;
            $_REQUEST["password"] = $user->password_plaintext;
        }

        // auto-create account if external login
        if (!$user && $external_login) {
            $reg = Contact::safe_registration($_REQUEST);
            if (!($user = Contact::find_by_email($_REQUEST["email"], $reg)))
                return $Conf->errorMsg($Conf->db_error_html(true, "while adding your account"));
            if (defval($Conf->settings, "setupPhase", false))
                return self::first_user($user, $msg);
        }

        // if no user found, then fail
        $user_password = $user ? $user->password : "";
        $cdb_password = $cdb_user && !$cdb_user->disable_shared_password ? $cdb_user->password : "";
        if (!$user && !$cdb_password) {
            $email_class = " error";
            return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . ". Did you enter the correct email address?");
        }

        // if user disabled, then fail
        if ($user && ($user->disabled || $user_password == "") && !$external_login)
            return $Conf->errorMsg("Your account is disabled. Contact the site administrator for more information.");

        // maybe reset password
        if ($_REQUEST["action"] == "forgot") {
            $user = $user ? : $cdb_user;
            $worked = $user->sendAccountInfo("forgot", true);
            if ($worked == "@resetpassword")
                $Conf->confirmMsg("A password reset link has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ". When you receive that email, follow its instructions to create a new password.");
            else if ($worked) {
                $Conf->confirmMsg("Your password has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ".  When you receive that email, return here to sign in.");
                $Conf->log("Sent password", $user);
            }
            return null;
        }

        // check password
        if (!$external_login) {
            if (($password = @trim($_REQUEST["password"])) === "") {
                $password_class = " error";
                return $Conf->errorMsg("Enter your password. If you’ve forgotten it, enter your email address and use the “I forgot my password” option.");
            }

            $user_match = $user_password && $user->check_password($password);
            $cdb_match = $cdb_password && $cdb_user->check_password($password);
            if (!$user_match && !$cdb_match) {
                $password_class = " error";
                return $Conf->errorMsg("That password doesn’t match. If you’ve forgotten your password, enter your email address and use the “I forgot my password” option.");
            }

            // maybe update database passwords
            if ($user && (!$user_match || $user->check_password_encryption(false))) {
                $user->change_password($password);
                edb_ql($Conf->dblink, "update ContactInfo set password=?? where contactId=??",
                       $user->password, $user->contactId);
            }
            if ($cdb_user && !$cdb_user->disable_shared_password
                && (!$cdb_match || $cdb_user->check_password_encryption(false))) {
                $cdb_user->change_password($password);
                edb_ql(Contact::contactdb(), "update ContactInfo set password=?? where contactDbId=??",
                       $cdb_user->password, $cdb_user->contactDbId);
            }
        }

        // mark activity
        if ($user && !$user->activity_at)
            $user->mark_activity();
        if ($cdb_user && !$cdb_user->activity_at)
            $cdb_user->mark_activity();

        // activate and redirect
        $user = $user ? : $cdb_user;
        $user = $user->activate();
        unset($_SESSION["testsession"]);
        $_SESSION["trueuser"] = (object) array("contactId" => $user->contactId, "dsn" => $Conf->dsn, "email" => $user->email);
        $Conf->save_session("freshlogin", true);

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
            return $Conf->errorMsg("Internal error: <code>\$Opt[\"ldapLogin\"]</code> is set, but this PHP installation doesn’t support LDAP.  Logins will fail until this error is fixed.");

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
        if (!$Conf->setting("bug_doubleencoding"))
            $Conf->q("insert into Settings (name, value) values ('bug_doubleencoding', 1)");
        foreach ($_REQUEST as $k => &$v)
            $v = rawurldecode($v);
        return true;
    }

    static private function create_account($user, $cdb_user) {
        global $Conf, $Opt, $email_class;

        // check for errors
        if (($user && $user->has_database_account() && $user->activity_at > 0)
            || ($cdb_user && $cdb_user->activity_at > 0)) {
            $email_class = " error";
            return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ". To retrieve your password, select “I forgot my password.”");
        } else if (!validate_email($_REQUEST["email"])) {
            $email_class = " error";
            return $Conf->errorMsg("&ldquo;" . htmlspecialchars($_REQUEST["email"]) . "&rdquo; is not a valid email address.");
        }

        // create database account
        if (!$user || !$user->has_database_account()) {
            if (!($user = Contact::find_by_email($_REQUEST["email"], true)))
                return $Conf->errorMsg($Conf->db_error_html(true, "while adding your account"));
        }

        $user->sendAccountInfo("create", true);
        $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".";

        // handle setup phase
        if (defval($Conf->settings, "setupPhase", false))
            return self::first_user($user, $msg);

        if ($Conf->allowEmailTo($user->email))
            $msg .= " A password has been emailed to you.  Return here when you receive it to complete the registration process.  If you don’t receive the email, check your spam folders and verify that you entered the correct address.";
        else {
            if ($Opt["sendEmail"])
                $msg .= " The email address you provided seems invalid.";
            else
                $msg .= " The conference system is not set up to mail passwords at this time.";
            $msg .= " Although an account was created for you, you need help to retrieve your password. Contact " . Text::user_html(Contact::site_contact()) . ".";
        }
        if (isset($_REQUEST["password"]) && trim($_REQUEST["password"]) != "")
            $msg .= " Note that the password you supplied on the login screen was ignored.";
        $Conf->confirmMsg($msg);
        return null;
    }

    static private function first_user($user, $msg) {
        global $Conf, $Opt;
        $msg .= " As the first user, you have been automatically signed in and assigned system administrator privilege.";
        if (!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]))
            $msg .= " Your password is “<tt>" . htmlspecialchars($user->password_plaintext) . "</tt>”. All later users will have to sign in normally.";
        $user->save_roles(Contact::ROLE_ADMIN, null);
        $Conf->save_setting("setupPhase", null);
        $Conf->confirmMsg($msg);
        return $user;
    }

}
