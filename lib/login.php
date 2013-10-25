<?php
// login.php -- HotCRP login helpers
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class LoginHelper {

    static function logout() {
        global $Me, $Conf, $Opt, $allowedSessionVars;
        if ($Me->valid() && isset($_REQUEST["signout"])
            && !isset($Opt["httpAuthLogin"]))
            $Conf->confirmMsg("You have been signed out. Thanks for using the system.");
        $Me->invalidate();
        $Me->fresh = true;
        if (isset($_REQUEST["signout"]))
            unset($Me->capabilities);
        foreach (array("l", "info", "rev_tokens", "rev_token_fail",
                       "comment_msgs", "pplscores", "pplscoresort",
                       "scoresort") as $v)
            unset($_SESSION[$v]);
        foreach ($allowedSessionVars as $v)
            unset($_SESSION[$v]);
        if (isset($_REQUEST["signout"])) {
            unset($_SESSION["afterLogin"]);
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
        if ($Me->valid())
            return;

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            $Conf->header("Error", "home", actionBar());
            $Conf->errorMsg("This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.");
            $Conf->footer();
            exit;
        }
        $_REQUEST["email"] = $_SERVER["REMOTE_USER"];
        if (validateEmail($_REQUEST["email"]))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"];
        else if (isset($Opt["defaultEmailDomain"])
                 && validateEmail($_REQUEST["email"] . "@" . $Opt["defaultEmailDomain"]))
            $_REQUEST["preferredEmail"] = $_REQUEST["email"] . "@" . $Opt["defaultEmailDomain"];
        $_REQUEST["action"] = "login";
        if (!self::login()) {
            $Conf->footer();
            exit;
        }
    }

    static function check_login() {
        global $Me;
        if (!self::login())
            $Me->invalidate();
    }

    static function login() {
        global $Conf, $Opt, $Me, $email_class, $password_class;
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
        if (!isset($_COOKIE["CRPTestCookie"]) && !isset($_REQUEST["cookie"])) {
            // set a cookie to test that their browser supports cookies
            setcookie("CRPTestCookie", true);
            $url = "cookie=1";
            foreach (array("email", "password", "action", "go", "afterLogin", "signin") as $a)
                if (isset($_REQUEST[$a]))
                    $url .= "&$a=" . urlencode($_REQUEST[$a]);
            go("?" . $url);
        } else if (!isset($_COOKIE["CRPTestCookie"]))
            return $Conf->errorMsg("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

        // do LDAP login before validation, since we might create an account
        if (isset($Opt["ldapLogin"])) {
            $_REQUEST["action"] = "login";
            if (!self::ldap_login())
                return false;
        }

        $Me->load_by_email($_REQUEST["email"]);
        if (!$Me->email && self::unquote_double_quoted_request())
            $Me->load_by_email($_REQUEST["email"]);
        if ($_REQUEST["action"] == "new") {
            if (!($reg = self::create_account()))
                return $reg;
            $_REQUEST["password"] = $Me->password_plaintext;
        }

        if (!$Me->validContact()) {
            if ($external_login) {
                if (!$Me->initialize($_REQUEST["email"], true))
                    return $Conf->errorMsg($Conf->db_error_html(true, "while adding your account"));
                if (defval($Conf->settings, "setupPhase", false))
                    return self::first_user($msg);
            } else {
                $email_class = " error";
                return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?");
            }
        }

        if (($Me->password == "" && !$external_login) || $Me->disabled)
            return $Conf->errorMsg("Your account is disabled. Contact the site administrator for more information.");

        if ($_REQUEST["action"] == "forgot") {
            $worked = $Me->sendAccountInfo(false, true);
            if ($worked == "@resetpassword")
                $Conf->confirmMsg("A password reset link has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ". When you receive that email, follow its instructions to create a new password.");
            else if ($worked) {
                $Conf->confirmMsg("Your password has been emailed to " . htmlspecialchars($_REQUEST["email"]) . ".  When you receive that email, return here to sign in.");
                $Conf->log("Sent password", $Me);
            }
            return null;
        }

        $_REQUEST["password"] = trim(defval($_REQUEST, "password", ""));
        if ($_REQUEST["password"] == "" && !isset($Opt["httpAuthLogin"])) {
            $password_class = " error";
            return $Conf->errorMsg("Enter your password. If you’ve forgotten it, enter your email address and use the “I forgot my password” option.");
        }

        if (!$external_login && !$Me->check_password($_REQUEST["password"])) {
            $password_class = " error";
            return $Conf->errorMsg("That password doesn’t match. If you’ve forgotten your password, enter your email address and use the “I forgot my password” option.");
        }

        $Conf->qe("update ContactInfo set visits=visits+1, lastLogin=" . time() . " where contactId=" . $Me->cid, "while recording login statistics");
        if (!$external_login && $Me->password_needs_upgrade()) {
            $Me->change_password($_REQUEST["password"]);
            $Conf->qe("update ContactInfo set password='" . sqlq($Me->password) . "' where contactId=" . $Me->cid, "while updating password");
        }

        if (isset($_REQUEST["go"]))
            $where = $_REQUEST["go"];
        else if (isset($_SESSION["afterLogin"]))
            $where = $_SESSION["afterLogin"];
        else
            $where = hoturl("index");

        setcookie("CRPTestCookie", false);
        unset($_SESSION["afterLogin"]);
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

    static private function create_account() {
        global $Conf, $Opt, $Me, $email_class;

        if ($Me->validContact() && $Me->visits > 0) {
            $email_class = " error";
            return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ". To retrieve your password, select “I forgot my password.”");
        } else if (!validateEmail($_REQUEST["email"])) {
            $email_class = " error";
            return $Conf->errorMsg("&ldquo;" . htmlspecialchars($_REQUEST["email"]) . "&rdquo; is not a valid email address.");
        } else if (!$Me->validContact()) {
            if (!$Me->initialize($_REQUEST["email"]))
                return $Conf->errorMsg($Conf->db_error_html(true, "while adding your account"));
        }

        $Me->sendAccountInfo(true, true);
        $Conf->log("Account created", $Me);
        $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".";

        // handle setup phase
        if (defval($Conf->settings, "setupPhase", false))
            return self::first_user($msg);

        if ($Conf->allowEmailTo($Me->email))
            $msg .= "  A password has been emailed to you.  Return here when you receive it to complete the registration process.  If you don’t receive the email, check your spam folders and verify that you entered the correct address.";
        else {
            if ($Opt['sendEmail'])
                $msg .= "  The email address you provided seems invalid.";
            else
                $msg .= "  The conference system is not set up to mail passwords at this time.";
            $msg .= "  Although an account was created for you, you need the site administrator’s help to retrieve your password.  The site administrator is " . htmlspecialchars($Opt["contactName"] . " <" . $Opt["contactEmail"] . ">") . ".";
        }
        if (isset($_REQUEST["password"]) && trim($_REQUEST["password"]) != "")
            $msg .= "  Note that the password you supplied on the login screen was ignored.";
        $Conf->confirmMsg($msg);
        return null;
    }

    static private function first_user($msg) {
        global $Conf, $Opt, $Me;
        $msg .= " As the first user, you have been automatically signed in and assigned system administrator privilege.";
        if (!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]))
            $msg .= "  Your password is “<tt>" . htmlspecialchars($Me->password_plaintext) . "</tt>”.  All later users will have to sign in normally.";
        $while = "while granting system administrator privilege";
        $Conf->qe("insert into ChairAssistant (contactId) values (" . $Me->cid . ")", $while);
        $Conf->qe("update ContactInfo set roles=" . (Contact::ROLE_ADMIN) . " where contactId=" . $Me->cid, $while);
        $Conf->qe("delete from Settings where name='setupPhase'", "while leaving setup phase");
        $Conf->log("Granted system administrator privilege to first user", $Me);
        $Conf->confirmMsg($msg);
        if (!function_exists("imagecreate"))
            $Conf->warnMsg("Your PHP installation appears to lack GD support, which is required for drawing score graphs.  You may want to fix this problem and restart Apache.");
        return true;
    }

}
